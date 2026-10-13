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

namespace tool_secbackup\event;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\event\site_enrolled::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\event\site_deregistered::class)]
/**
 * Tests for the events.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\event\site_enrolled
 * @covers     \tool_secbackup\event\site_deregistered
 */
final class events_test extends \advanced_testcase {
    /** @var array Values of other. */
    private const OTHER = ['endpoint' => 'https://app.example.test/api/lms-backup/v1',
        'siteid' => '11111111-2222-4333-8444-555555555555'];

    public function test_enrolled(): void {
        $event = site_enrolled::create(['context' => \context_system::instance(), 'other' => self::OTHER + ['again' => false]]);
        $this->assertSame(get_string('event_enrolled', 'tool_secbackup'), site_enrolled::get_name());
        $this->assertStringContainsString(self::OTHER['endpoint'], $event->get_description());
        $this->assertSame('/admin/tool/secbackup/index.php', $event->get_url()->out_as_local_url(false));
        $this->assertFalse(site_enrolled::get_other_mapping());
        $this->assertSame('c', $event->crud);
    }

    public function test_deregistered(): void {
        $event = site_deregistered::create(['context' => \context_system::instance(), 'other' => self::OTHER]);
        $this->assertSame(get_string('event_deregistered', 'tool_secbackup'), site_deregistered::get_name());
        $this->assertStringContainsString(self::OTHER['siteid'], $event->get_description());
        $this->assertSame('/admin/tool/secbackup/index.php', $event->get_url()->out_as_local_url(false));
        $this->assertFalse(site_deregistered::get_other_mapping());
        $this->assertSame('d', $event->crud);
    }

    public function test_enrolled_can_be_triggered(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        site_enrolled::create(['context' => \context_system::instance(), 'other' => self::OTHER + ['again' => true]])->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(site_enrolled::class, $events[0]);
        $this->assertSame(self::OTHER + ['again' => true], $events[0]->other);
    }

    public function test_deregistered_can_be_triggered(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        site_deregistered::create(['context' => \context_system::instance(), 'other' => self::OTHER])->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(site_deregistered::class, $events[0]);
        $this->assertSame(self::OTHER, $events[0]->other);
    }

    public function test_enrolled_needs_its_values(): void {
        $this->expectException(\coding_exception::class);
        site_enrolled::create(['context' => \context_system::instance(), 'other' => self::OTHER]);
    }

    public function test_deregistered_needs_its_values(): void {
        $this->expectException(\coding_exception::class);
        site_deregistered::create(['context' => \context_system::instance(), 'other' => ['endpoint' => 'x']]);
    }
}
