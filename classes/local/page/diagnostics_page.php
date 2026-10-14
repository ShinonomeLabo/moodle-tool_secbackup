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

use tool_secbackup\form\diagnostics_form;
use tool_secbackup\local\diagnostics\runner;
use tool_secbackup\local\endpoint\endpoint;
use tool_secbackup\local\enrollment\state;
use tool_secbackup\local\plugin_exception;
use tool_secbackup\local\trust\developer_settings;

/**
 * The connection diagnostics page. A registered site is checked at once; otherwise a connection URL can be typed.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnostics_page {
    /** @var runner|null Runner; null for the one of this site. */
    private ?runner $runner;

    /** @var endpoint|null Connection URL to check. */
    private ?endpoint $endpoint = null;

    /** @var diagnostics_form|null Form for a connection URL, for a site that is not registered. */
    private ?diagnostics_form $form = null;

    /** @var string Error message, as plain text. */
    private string $error = '';

    /**
     * Constructor: checks access.
     *
     * @param runner|null $runner Runner; null for the one of this site.
     */
    public function __construct(?runner $runner = null) {
        access::check();
        $this->runner = $runner;
    }

    /**
     * Finds the connection URL to check.
     */
    public function handle(): void {
        $state = state::load();
        if ($state->is_enrolled()) {
            try {
                $this->endpoint = $state->endpoint();
            } catch (plugin_exception $e) {
                $this->error = $e->message();
            }
            return;
        }
        $this->form = new diagnostics_form(new \moodle_url('/admin/tool/secbackup/diagnostics.php'));
        $data = $this->form->get_data();
        if ($data !== null) {
            $this->endpoint = endpoint::parse($data->endpoint, developer_settings::current());
        }
    }

    /**
     * The body of the page.
     *
     * @return string
     */
    public function render(): string {
        global $PAGE;
        $renderer = $PAGE->get_renderer('tool_secbackup');
        $out = $renderer->page_top(developer_settings::current());
        if ($this->error !== '') {
            $out .= $renderer->error_message($this->error);
        }
        if ($this->form !== null) {
            $out .= $this->form->render();
        }
        return $out . $renderer->diagnostics(($this->runner ?? runner::create())->run($this->endpoint));
    }
}
