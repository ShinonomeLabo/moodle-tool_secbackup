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

namespace tool_secbackup\local\enrollment;

use tool_secbackup\event\site_deregistered;

/**
 * Removes the registration on this site. The service is not told; the page asks the administrator to
 * remove the site or issue a new registration code on the platform as well.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deregistration {
    /**
     * Whether the typed confirmation is the site name (compatibility forms and surrounding spaces ignored).
     *
     * @param string $typed Typed text.
     * @param string $sitename Site name on the platform.
     * @return bool
     */
    public static function confirmation_matches(string $typed, string $sitename): bool {
        $typed = self::normalize($typed);
        return $typed !== '' && $typed === self::normalize($sitename);
    }

    /**
     * NFKC without surrounding spaces; empty for invalid UTF-8.
     *
     * @param string $text Text.
     * @return string
     */
    private static function normalize(string $text): string {
        return trim((string) \Normalizer::normalize($text, \Normalizer::FORM_KC));
    }

    /**
     * Forgets the registration, logs it and tells the site administrators.
     */
    public static function run(): void {
        $state = state::load();
        if (!$state->is_enrolled()) {
            return;
        }
        state::clear();
        site_deregistered::create([
            'context' => \context_system::instance(),
            'other' => ['endpoint' => $state->endpoint_url(), 'siteid' => $state->site_id()],
        ])->trigger();
        notifier::send('deregistered', $state->platform_name(), $state->site_name(), $state->endpoint_url());
    }
}
