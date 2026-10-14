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

namespace tool_secbackup\local\cli;

use tool_secbackup\local\diagnostics\check_result;
use tool_secbackup\local\diagnostics\runner;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

/**
 * The connection diagnostics on the command line, with the same checks as the page.
 *
 * Run it as the user of cron: SELinux and file permissions may treat it differently from the web server.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_command {
    /** @var runner|null Runner; null for the one of this site. */
    private ?runner $runner;

    /**
     * Constructor.
     *
     * @param runner|null $runner Runner; null for the one of this site.
     */
    public function __construct(?runner $runner = null) {
        $this->runner = $runner;
    }

    /**
     * Runs the checks and writes one line per check.
     *
     * @param string $endpointinput The --endpoint option; empty for the registered URL.
     * @param callable $write Writes one line.
     * @return int Exit code: 1 when a check found a problem or the URL is wrong, otherwise 0.
     */
    public function run(string $endpointinput, callable $write): int {
        $state = state::load();
        try {
            $endpoint = $endpointinput !== '' ? endpoint::parse($endpointinput, developer_settings::current())
                : ($state->is_enrolled() ? $state->endpoint() : null);
        } catch (plugin_exception $e) {
            $write($e->message());
            return 1;
        }
        $write(get_string('diag_cli_heading', 'tool_secbackup'));
        $write($endpoint === null ? get_string('diag_cli_noendpoint', 'tool_secbackup')
            : get_string('diag_cli_endpoint', 'tool_secbackup', $endpoint->url()));
        $code = 0;
        foreach (($this->runner ?? runner::create())->run($endpoint) as $result) {
            $write('[' . get_string('result_' . $result->result, 'tool_secbackup') . '] '
                . get_string('check_' . $result->check, 'tool_secbackup') . ': ' . $result->detail);
            if ($result->advice !== '') {
                $write('    ' . get_string('diag_cli_advice', 'tool_secbackup', $result->advice));
            }
            if ($result->result === check_result::FAIL) {
                $code = 1;
            }
        }
        return $code;
    }
}
