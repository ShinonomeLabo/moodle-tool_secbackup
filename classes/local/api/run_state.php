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
 * The state of a run at the service.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_state {
    /** @var string Status, such as uploading or completed. */
    private string $status;

    /** @var string|null Reason of a failure. */
    private ?string $failurecode;

    /** @var array|null The receipt envelope, as it came; nothing in it is verified yet. */
    private ?array $receipt;

    /**
     * Constructor.
     *
     * @param string $status Status.
     * @param string|null $failurecode Reason of a failure.
     * @param array|null $receipt The receipt envelope.
     */
    public function __construct(string $status, ?string $failurecode, ?array $receipt) {
        $this->status = $status;
        $this->failurecode = $failurecode;
        $this->receipt = $receipt;
    }

    /**
     * Status.
     *
     * @return string
     */
    public function status(): string {
        return $this->status;
    }

    /**
     * Reason of a failure.
     *
     * @return string|null
     */
    public function failure_code(): ?string {
        return $this->failurecode;
    }

    /**
     * The receipt envelope, to be checked with receipt::verify().
     *
     * @return array|null
     */
    public function receipt(): ?array {
        return $this->receipt;
    }
}
