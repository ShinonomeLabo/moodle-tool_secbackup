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

namespace tool_secbackup\local\backup;

use tool_secbackup\local\api\client;
use tool_secbackup\local\crypto\signing_keypair;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\net\object_transport;
use tool_secbackup\local\plugin_exception;

/**
 * Compares the files of the Moodle program on this site with the list of the official files that the backup service has.
 *
 * The list holds the SHA-256 of every file of the distribution of one build of one version, by the path from the folder of
 * the distribution, in byte order. The same rules make the list of this site: the folder is $CFG->root on Moodle 5.1 and
 * later (it holds public/) and $CFG->dirroot before; symbolic links are not followed; a file that is not a regular file is
 * left out; the folders .git and node_modules are left out at any depth; config.php and config-dist.php are left out in the
 * root only; and the folders of the plugins that were added to the site are left out, because they are sent as code. A
 * difference between the two layouts is known only to site_root() and release_parts().
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class core_diff {
    /** @var int The most bytes of a list of official files, as it is and when it is expanded. */
    public const MAX_LIST_BYTES = 67108864;

    /** @var int The files that are asked of the walker at a time. */
    private const PAGE = 1000;

    /** @var string[] Folders that are never listed, at any depth. */
    private const SKIPPED_FOLDERS = ['.git', 'node_modules'];

    /** @var string[] Files that are not listed in the root. */
    private const SKIPPED_ROOT_FILES = ['config.php', 'config-dist.php'];

    /** @var string The folder of the distribution. */
    private string $root;

    /** @var string[]|null Folders of the added plugins; null for those of this site. */
    private ?array $pluginroots;

    /** @var \Closure|null Lists a folder instead of scandir(), for tests. */
    private ?\Closure $lister;

    /** @var \Closure|null Gives the hash of a file instead of hash_file(), for tests. */
    private ?\Closure $hasher;

    /** @var string[] Paths of files whose hash could not be made. */
    private array $unhashable = [];

    /** @var tree_walker|null The walk of the last local_hashes(). */
    private ?tree_walker $walker = null;

    /**
     * Constructor.
     *
     * @param string|null $root The folder of the distribution; null for that of this site.
     * @param string[]|null $pluginroots Folders of the added plugins; null for those of this site.
     * @param \Closure|null $lister Lists a folder instead of scandir(), for tests: takes the path, gives names or false.
     * @param \Closure|null $hasher Gives the SHA-256 of a file instead of hash_file(), for tests: takes the path.
     */
    public function __construct(
        ?string $root = null,
        ?array $pluginroots = null,
        ?\Closure $lister = null,
        ?\Closure $hasher = null
    ) {
        $this->root = rtrim($root ?? self::site_root(), '/');
        $this->pluginroots = $pluginroots;
        $this->lister = $lister;
        $this->hasher = $hasher;
    }

    /**
     * The folder of the distribution on this site.
     *
     * @return string $CFG->root when there is one with public/ in it (Moodle 5.1 and later), else $CFG->dirroot.
     */
    public static function site_root(): string {
        global $CFG;
        return isset($CFG->root) && is_dir($CFG->root . '/public') ? $CFG->root : $CFG->dirroot;
    }

    /**
     * The folders of the plugins that were added to the site.
     *
     * @return string[]
     */
    public function plugin_roots(): array {
        return $this->pluginroots ?? array_values(code_source::roots());
    }

    /**
     * The hash of every file of the distribution that the list of official files would hold, in the order of the list.
     *
     * @return \Generator<string, string> SHA-256 in hexadecimal by path from the folder of the distribution, "/" between names.
     */
    public function local_hashes(): \Generator {
        $this->unhashable = [];
        $this->walker = new tree_walker($this->root, $this->excluded(), $this->list_names(...));
        $after = null;
        while (($page = $this->walker->next($after, self::PAGE)) !== []) {
            foreach ($page as $item) {
                $after = $item['path'];
                $file = $this->root . '/' . $item['path'];
                $hash = $this->hasher !== null ? ($this->hasher)($file) : error_silencer::run(
                    static fn() => hash_file('sha256', $file),
                    $warning
                );
                if ($hash === false) {
                    $this->unhashable[] = $item['path'];
                    continue;
                }
                yield $item['path'] => $hash;
            }
        }
    }

    /**
     * What the last local_hashes() could not list: a name that a list cannot hold ("hex:" and the hexadecimal of its path), a
     * folder that could not be listed and a file that could not be read (their paths).
     *
     * @return string[]
     */
    public function unlisted(): array {
        return array_merge($this->unhashable, $this->walker === null ? [] : $this->walker->unreadable());
    }

    /**
     * Compares the list of the official files with the files of this site.
     *
     * @param string $officialgzip The list of the official files, compressed.
     * @param iterable $local Hash by path as local_hashes() gives them: in byte order of the paths.
     * @return string[][] ['modified' => paths in both with another hash, 'added' => paths only on this site,
     *         'deleted' => paths only in the official list], each in byte order.
     * @throws plugin_exception With core_manifest_mismatch when the list is for another version or build than this site's,
     *         and with core_manifest_invalid when it cannot be used.
     * @throws \coding_exception When the paths of $local are not in order.
     */
    public function compare(string $officialgzip, iterable $local): array {
        $text = error_silencer::run(static fn() => gzdecode($officialgzip, self::MAX_LIST_BYTES), $warning);
        // The limit of gzdecode() is checked in steps, so a text a little over it can come through: the length is checked too.
        if (!is_string($text) || strlen($text) > self::MAX_LIST_BYTES) {
            throw new plugin_exception('core_manifest_invalid', null, 'unreadable');
        }
        $lines = self::lines($text);
        $header = self::header($lines->current());
        $mine = $this->release_parts();
        if ($header['version'] !== $mine['version'] || $header['build'] !== $mine['build']) {
            throw new plugin_exception('core_manifest_mismatch');
        }
        $lines->next();
        $official = self::official_entries($lines, $header['count']);
        $localentries = self::local_entries($local);
        $result = ['modified' => [], 'added' => [], 'deleted' => []];
        while ($official->valid() || $localentries->valid()) {
            $order = !$official->valid() ? 1 : (!$localentries->valid() ? -1 : strcmp($official->key(), $localentries->key()));
            if ($order < 0) {
                $result['deleted'][] = $official->key();
                $official->next();
            } else if ($order > 0) {
                $result['added'][] = $localentries->key();
                $localentries->next();
            } else {
                if ($official->current() !== $localentries->current()) {
                    $result['modified'][] = $official->key();
                }
                $official->next();
                $localentries->next();
            }
        }
        return $result;
    }

    /**
     * The version and the build of the Moodle program on this site, read as text from its version.php.
     *
     * The file is never run. It is the one in the folder of the distribution, or else the one in its public/ folder (Moodle
     * 5.1 and later keep it there).
     *
     * @return string[] ['version' => such as 2024100703.10, 'release' => the text of $release, 'build' => such as 20250113].
     * @throws plugin_exception With core_manifest_invalid when the file is missing or has no usable version and release.
     */
    public function release_parts(): array {
        $text = false;
        foreach ([$this->root . '/version.php', $this->root . '/public/version.php'] as $file) {
            if ($text === false && is_file($file)) {
                $text = error_silencer::run(static fn() => file_get_contents($file), $warning);
            }
        }
        $found = is_string($text)
            && preg_match('~^\s*\$version\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*;~m', $text, $version) === 1
            && preg_match('~^\s*\$release\s*=\s*(?:\'([^\']*)\'|"([^"]*)")\s*;~m', $text, $release) === 1;
        $release = ($release[1] ?? '') . ($release[2] ?? '');
        $number = strpos($version[1] ?? '', '.') === false ? ($version[1] ?? '') . '.00' : ($version[1] ?? '');
        if (
            !$found
            || preg_match('/^\d{10}\.\d{2}\z/', $number) !== 1
            || preg_match('/Build: (\d{8})(?!\d)/', $release, $build) !== 1
        ) {
            throw new plugin_exception('core_manifest_invalid', null, 'version.php');
        }
        return ['version' => $number, 'release' => $release, 'build' => $build[1]];
    }

    /**
     * Gets the list of the official files for the build of this site and checks it against what the service signed.
     *
     * A list that is not there, that does not have the signed size or hash, or that the storage does not have, gives null:
     * the backup then sends the whole Moodle program, which is safe and only more data. An error of the connection or of
     * the service is not hidden that way: it is thrown, so that a failure of the network does not become a full upload.
     *
     * The type of $pair is written the way the PHPDoc checker of moodle-plugin-ci reads the signature (see transport::send()).
     *
     * @param client $client The client of the service.
     * @param endpoint $endpoint The connection URL.
     * @param #[|\SensitiveParameter|]|signing_keypair $pair The key pair of this site.
     * @param string $siteid The site's ID.
     * @param object_transport $objects The transport of the storage.
     * @param string $tempdir A folder for the temporary file.
     * @return string|null The compressed list, or null.
     * @throws \coding_exception When the temporary folder cannot be used.
     * @throws plugin_exception From the client and from the transport; core_manifest_invalid for a version.php that cannot be
     *         used.
     */
    public function fetch_official(
        client $client,
        endpoint $endpoint,
        #[\SensitiveParameter] signing_keypair $pair,
        string $siteid,
        object_transport $objects,
        string $tempdir
    ): ?string {
        $parts = $this->release_parts();
        $ref = $client->core_manifest($endpoint, $pair, $siteid, $parts['version'], $parts['build']);
        if ($ref === null || $ref->size() < 1 || $ref->size() > self::MAX_LIST_BYTES) {
            return null;
        }
        $path = is_dir($tempdir) && is_writable($tempdir)
            ? error_silencer::run(static fn() => tempnam($tempdir, 'core-'), $warning)
            : false;
        if ($path === false) {
            throw new \coding_exception('The temporary folder cannot be used.');
        }
        try {
            if (!$objects->get_to_file($ref->url(), $path, $ref->size())) {
                return null;
            }
            $bytes = (string) file_get_contents($path);
            $signed = hash_equals($ref->sha256(), base64_encode(hash('sha256', $bytes, true)));
            return $signed && strlen($bytes) === $ref->size() ? $bytes : null;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * The relative paths that the walk leaves out.
     *
     * @return string[]
     */
    private function excluded(): array {
        $excluded = self::SKIPPED_ROOT_FILES;
        $realroot = realpath($this->root);
        foreach ($this->plugin_roots() as $dir) {
            $real = realpath($dir);
            if ($realroot !== false && $real !== false && str_starts_with($real, rtrim($realroot, '/') . '/')) {
                $excluded[] = substr($real, strlen(rtrim($realroot, '/')) + 1);
            }
        }
        return $excluded;
    }

    /**
     * The names in a folder, without the folders that are never listed.
     *
     * A file or a link with the name of such a folder is not left out here: a link is the walker's to note, and a file is
     * listed as the service lists it.
     *
     * @param string $dir The folder.
     * @return string[]|false The names, or false when the folder cannot be listed.
     */
    private function list_names(string $dir): array|false {
        $names = $this->lister !== null ? ($this->lister)($dir) : error_silencer::run(static fn() => scandir($dir), $warning);
        if ($names === false) {
            return false;
        }
        return array_values(array_filter($names, static function (string $name) use ($dir): bool {
            $path = $dir . '/' . $name;
            return !in_array($name, self::SKIPPED_FOLDERS, true) || is_link($path) || !is_dir($path);
        }));
    }

    /**
     * The lines of a text, without their line feeds; a last line may lack the line feed.
     *
     * @param string $text The text.
     * @return \Generator<int, string>
     */
    private static function lines(string $text): \Generator {
        $length = strlen($text);
        $position = 0;
        while ($position < $length) {
            $end = strpos($text, "\n", $position);
            $end = $end === false ? $length : $end;
            yield substr($text, $position, $end - $position);
            $position = $end + 1;
        }
    }

    /**
     * Checks the first line of a list.
     *
     * @param string|null $line The line; null when the list is empty.
     * @return array ['version' => string, 'build' => string, 'count' => int]
     * @throws plugin_exception With core_manifest_invalid.
     */
    private static function header(?string $line): array {
        $data = $line === null ? null : json_decode($line, true, 2);
        $keys = ['build', 'count', 'release', 'v', 'version'];
        if (is_array($data)) {
            ksort($data);
        }
        $valid = is_array($data) && array_keys($data) === $keys && $data['v'] === 1
            && is_string($data['version']) && is_string($data['build']) && is_string($data['release'])
            && is_int($data['count']) && $data['count'] >= 0;
        if (!$valid) {
            throw new plugin_exception('core_manifest_invalid', null, 'first line');
        }
        return ['version' => $data['version'], 'build' => $data['build'], 'count' => $data['count']];
    }

    /**
     * The files of a list after its first line, checked as they are read.
     *
     * @param \Generator $lines The lines, after the first one.
     * @param int $count The number of files that the first line says.
     * @return \Generator<string, string> Hash by path.
     * @throws plugin_exception With core_manifest_invalid for a bad line, paths not in order, or another number of lines.
     */
    private static function official_entries(\Generator $lines, int $count): \Generator {
        $previous = null;
        $seen = 0;
        for (; $lines->valid(); $lines->next()) {
            $line = $lines->current();
            $seen++;
            if (
                $seen > $count
                || preg_match('~^([^\x00-\x1f\x7f]+)\t([0-9a-f]{64})\z~u', $line, $match) !== 1
                || ($previous !== null && strcmp($previous, $match[1]) >= 0)
            ) {
                throw new plugin_exception('core_manifest_invalid', null, 'line ' . ($seen + 1));
            }
            $previous = $match[1];
            yield $match[1] => $match[2];
        }
        if ($seen !== $count) {
            throw new plugin_exception('core_manifest_invalid', null, 'count');
        }
    }

    /**
     * The files of this site, checked to be in order.
     *
     * @param iterable $local Hash by path.
     * @return \Generator<string, string>
     * @throws \coding_exception When the paths are not in byte order.
     */
    private static function local_entries(iterable $local): \Generator {
        $previous = null;
        foreach ($local as $path => $hash) {
            $path = (string) $path;
            if ($previous !== null && strcmp($previous, $path) >= 0) {
                throw new \coding_exception('The paths of the files of this site are in byte order.');
            }
            $previous = $path;
            yield $path => (string) $hash;
        }
    }
}
