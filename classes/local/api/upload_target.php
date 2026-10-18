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

namespace tool_secbackup\local\api;

use tool_secbackup\local\net\object_url;

/**
 * Where, and with which headers, one object is sent.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upload_target {
    /** @var string object, manifest or index. */
    private string $kind;

    /** @var string|null Name of the chunk (objects only). */
    private ?string $name;

    /** @var object_url Where the object is sent to. */
    private object_url $url;

    /** @var string[] The five headers that are sent as they are, by the name that the service gave. */
    private array $headers;

    /** @var int When the URL stops working (UNIX time). */
    private int $expiresat;

    /**
     * Constructor.
     *
     * @param string $kind object, manifest or index.
     * @param string|null $name Name of the chunk.
     * @param object_url $url The URL.
     * @param string[] $headers The headers.
     * @param int $expiresat Time the URL expires.
     */
    public function __construct(string $kind, ?string $name, object_url $url, array $headers, int $expiresat) {
        $this->kind = $kind;
        $this->name = $name;
        $this->url = $url;
        $this->headers = $headers;
        $this->expiresat = $expiresat;
    }

    /**
     * Kind of the object.
     *
     * @return string
     */
    public function kind(): string {
        return $this->kind;
    }

    /**
     * Name of the chunk.
     *
     * @return string|null Null for the manifest and the index.
     */
    public function name(): ?string {
        return $this->name;
    }

    /**
     * Where the object is sent to.
     *
     * @return object_url
     */
    public function url(): object_url {
        return $this->url;
    }

    /**
     * The headers, which are sent as they are.
     *
     * @return string[]
     */
    public function headers(): array {
        return $this->headers;
    }

    /**
     * When the URL stops working.
     *
     * @return int UNIX time.
     */
    public function expires_at(): int {
        return $this->expiresat;
    }
}
