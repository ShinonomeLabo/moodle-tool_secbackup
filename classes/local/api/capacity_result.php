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

/**
 * The service's decision on the size a run expects to store.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class capacity_result {
    /** @var bool Whether the run may go on. */
    private bool $continue;

    /** @var int|null Bytes the plan allows, or null for no limit. */
    private ?int $limitbytes;

    /** @var int Bytes stored already. */
    private int $usedbytes;

    /**
     * Constructor.
     *
     * @param bool $continue Whether the run may go on.
     * @param int|null $limitbytes Bytes the plan allows.
     * @param int $usedbytes Bytes stored already.
     */
    public function __construct(bool $continue, ?int $limitbytes, int $usedbytes) {
        $this->continue = $continue;
        $this->limitbytes = $limitbytes;
        $this->usedbytes = $usedbytes;
    }

    /**
     * Whether the run may go on.
     *
     * @return bool
     */
    public function continue(): bool {
        return $this->continue;
    }

    /**
     * Bytes the plan allows.
     *
     * @return int|null Null for no limit.
     */
    public function limit_bytes(): ?int {
        return $this->limitbytes;
    }

    /**
     * Bytes stored already.
     *
     * @return int
     */
    public function used_bytes(): int {
        return $this->usedbytes;
    }
}
