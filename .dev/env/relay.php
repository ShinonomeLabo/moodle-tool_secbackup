<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A small TCP relay for the Behat runs in Safari.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
// A small TCP relay for the Behat runs in Safari. Development only; not part of the plugin package.
//
// Safari runs on this Mac, outside Docker, so two connections need help:
//  - the container reaches safaridriver as host.docker.internal, and safaridriver refuses every Host header but localhost:
//      php relay.php 4446 4445 localhost       (on the Mac; the Host header of each request is replaced)
//  - the Behat command checks, from inside the container, the address that the Mac's browser uses for the Behat site:
//      php relay.php 18053 80                  (in the web server container; the bytes are passed on as they are)
// Usage: php relay.php <listen port> <target port> [host name for the Host header]
// It listens on 127.0.0.1 only, and connects to 127.0.0.1 only. Started and stopped by .dev/env/behat.sh.

$listen = (int) ($argv[1] ?? 0);
$target = (int) ($argv[2] ?? 0);
$rewrite = $argv[3] ?? null;
if ($listen < 1 || $target < 1) {
    fwrite(STDERR, "usage: php relay.php <listen port> <target port> [host name]\n");
    exit(1);
}
$server = stream_socket_server("tcp://127.0.0.1:$listen", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "cannot listen on 127.0.0.1:$listen: $errstr\n");
    exit(1);
}

/**
 * Takes the complete requests off the front of a buffer, with the Host header replaced.
 *
 * @param array $conn The connection: 'buffer' holds bytes from the client, 'remaining' the body bytes still to pass on.
 * @param string|null $rewrite Host name for the Host header; null passes the bytes on unchanged.
 * @return string Bytes to send to safaridriver.
 */
function secbk_relay_requests(array &$conn, ?string $rewrite): string {
    if ($rewrite === null) {
        $all = $conn['buffer'];
        $conn['buffer'] = '';
        return $all;
    }
    $out = '';
    while ($conn['buffer'] !== '') {
        if ($conn['remaining'] > 0) {
            $part = substr($conn['buffer'], 0, $conn['remaining']);
            $conn['remaining'] -= strlen($part);
            $conn['buffer'] = (string) substr($conn['buffer'], strlen($part));
            $out .= $part;
            continue;
        }
        $end = strpos($conn['buffer'], "\r\n\r\n");
        if ($end === false) {
            break;
        }
        $head = substr($conn['buffer'], 0, $end);
        $conn['buffer'] = (string) substr($conn['buffer'], $end + 4);
        $head = preg_replace('/^Host:[^\r\n]*/mi', 'Host: ' . $rewrite, $head);
        if (preg_match('/^Content-Length:\s*(\d+)/mi', $head, $match)) {
            $conn['remaining'] = (int) $match[1];
        }
        $out .= $head . "\r\n\r\n";
    }
    return $out;
}

$connections = [];
while (true) {
    $read = [$server];
    foreach ($connections as $conn) {
        $read[] = $conn['client'];
        $read[] = $conn['driver'];
    }
    $write = null;
    $except = null;
    if (stream_select($read, $write, $except, 1) < 1) {
        continue;
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = stream_socket_accept($server, 0);
            $driver = $client === false ? false : @stream_socket_client("tcp://127.0.0.1:$target", $errno, $errstr, 5);
            if ($client !== false && $driver === false) {
                fclose($client);
            } elseif ($client !== false) {
                $connections[] = ['client' => $client, 'driver' => $driver, 'buffer' => '', 'remaining' => 0];
            }
            continue;
        }
        foreach ($connections as $id => &$conn) {
            if ($stream !== $conn['client'] && $stream !== $conn['driver']) {
                continue;
            }
            $data = fread($stream, 65536);
            if ($data === false || $data === '') {
                fclose($conn['client']);
                fclose($conn['driver']);
                unset($connections[$id]);
                break;
            }
            if ($stream === $conn['client']) {
                $conn['buffer'] .= $data;
                fwrite($conn['driver'], secbk_relay_requests($conn, $rewrite));
            } else {
                fwrite($conn['client'], $data);
            }
            break;
        }
        unset($conn);
    }
}
