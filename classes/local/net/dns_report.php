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

namespace tool_secbackup\local\net;

/**
 * The answers of each name source for one host.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dns_report {
    /** @var array IPv4 addresses from each DNS over HTTPS resolver by name; null when it did not answer. */
    public readonly array $doh;

    /** @var string[]|null IPv4 addresses from the operating system; null when an HTTP proxy resolves the name. */
    public readonly ?array $os;

    /** @var bool Whether no resolver answered because every connection to them failed at once (cURL error 7). */
    public readonly bool $connectfailed;

    /**
     * Constructor.
     *
     * @param array $doh Answers by resolver name.
     * @param string[]|null $os The operating system's answer.
     * @param bool $connectfailed Whether every connection to the resolvers failed at once.
     */
    public function __construct(array $doh, ?array $os, bool $connectfailed = false) {
        $this->doh = $doh;
        $this->os = $os;
        $this->connectfailed = $connectfailed;
    }

    /**
     * The address to connect to: the operating system's first answer.
     *
     * @return string|null Null when an HTTP proxy resolves the name or there is no answer.
     */
    public function pinned(): ?string {
        return $this->os[0] ?? null;
    }
}
