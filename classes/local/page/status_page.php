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

namespace tool_secbackup\local\page;

use core\output\notification;
use tool_secbackup\local\backup\env\external_storage;
use tool_secbackup\local\backup\starter;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\trust\developer_settings;

/**
 * The status page.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class status_page {
    /** @var callable|null Why the server is too busy, or null; a seam for tests (see starter::start_now()). */
    private $verdict;

    /** @var external_storage|null The detection of an external file store; null for the one of this site. */
    private ?external_storage $storage;

    /**
     * Constructor: checks access.
     *
     * @param callable|null $verdict A seam for tests: function () that returns why the server is too busy, or null.
     * @param external_storage|null $storage The detection of an external file store; null for the one of this site.
     */
    public function __construct(?callable $verdict = null, ?external_storage $storage = null) {
        access::check();
        $this->verdict = $verdict;
        $this->storage = $storage;
    }

    /**
     * Handles the button "Back up now".
     *
     * @return redirection|null Where to go next, with what the starter answered; null when the button was not pressed.
     */
    public function handle(): ?redirection {
        if (optional_param('action', '', PARAM_ALPHA) !== 'start') {
            return null;
        }
        require_sesskey();
        $answer = starter::start_now(time(), $this->verdict);
        $type = ['started' => notification::NOTIFY_SUCCESS, 'running' => notification::NOTIFY_INFO][$answer]
            ?? notification::NOTIFY_WARNING;
        return new redirection(
            new \moodle_url('/admin/tool/secbackup/index.php'),
            get_string('start_' . $answer, 'tool_secbackup'),
            $type
        );
    }

    /**
     * The body of the page.
     *
     * @return string
     */
    public function render(): string {
        global $PAGE;
        $renderer = $PAGE->get_renderer('tool_secbackup');
        $state = state::load();
        if ($state->is_enrolled()) {
            $this->warn($renderer, $state);
        }
        return $renderer->page_top(developer_settings::current()) . $renderer->status($state);
    }

    /**
     * Asks Moodle's dialog to show the warnings that apply to this site: files in an external cloud service, and a Moodle
     * program that may have been modified. The texts are the ones of the design of the plugin.
     *
     * @param \tool_secbackup\output\renderer $renderer The renderer.
     * @param state $state The registration, which has the name of the platform.
     */
    private function warn(\tool_secbackup\output\renderer $renderer, state $state): void {
        if (($this->storage ?? new external_storage())->detected()) {
            $renderer->warning_dialog(
                get_string('modal_external_title', 'tool_secbackup'),
                get_string('modal_external', 'tool_secbackup', $state->platform_name())
            );
        }
        if (get_config('tool_secbackup', 'coremodified') === '1') {
            $renderer->warning_dialog(get_string('modal_core_title', 'tool_secbackup'), get_string('modal_core', 'tool_secbackup'));
        }
    }
}
