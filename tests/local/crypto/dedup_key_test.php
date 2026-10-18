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

namespace tool_secbackup\local\crypto;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\dedup_key::class)]
/**
 * Tests for the key that makes the names of the chunks.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\dedup_key
 */
final class dedup_key_test extends \advanced_testcase {
    public function test_the_key_is_created_once_and_kept(): void {
        $this->resetAfterTest();
        $this->assertFalse(get_config('tool_secbackup', 'dedupsecret'));
        $key = dedup_key::load_or_create();
        $this->assertSame(32, strlen($key));
        $this->assertSame($key, dedup_key::load_or_create());
    }

    public function test_the_key_is_stored_wrapped_by_the_encryption_of_moodle(): void {
        $this->resetAfterTest();
        $key = dedup_key::load_or_create();
        $stored = get_config('tool_secbackup', 'dedupsecret');
        $this->assertIsString($stored);
        foreach ([$key, base64_encode($key), bin2hex($key)] as $form) {
            $this->assertStringNotContainsString($form, $stored);
        }
        $this->assertSame(base64_encode($key), \core\encryption::decrypt($stored));
    }

    public function test_the_key_is_read_back_from_the_settings(): void {
        $this->resetAfterTest();
        $key = random_bytes(32);
        set_config('dedupsecret', \core\encryption::encrypt(base64_encode($key)), 'tool_secbackup');
        $this->assertSame($key, dedup_key::load_or_create());
    }

    public function test_two_sites_get_different_keys(): void {
        $this->resetAfterTest();
        $first = dedup_key::load_or_create();
        dedup_key::forget();
        $this->assertFalse(get_config('tool_secbackup', 'dedupsecret'));
        $this->assertNotSame($first, dedup_key::load_or_create());
    }

    public function test_an_empty_stored_value_means_no_key(): void {
        $this->resetAfterTest();
        set_config('dedupsecret', '', 'tool_secbackup');
        $key = dedup_key::load_or_create();
        $this->assertSame(32, strlen($key));
        $this->assertSame(base64_encode($key), \core\encryption::decrypt(get_config('tool_secbackup', 'dedupsecret')));
    }

    /**
     * Stored values that are not a key.
     *
     * @return array
     */
    public static function corrupt_provider(): array {
        return [
            'not base64' => ['not base64 at all!'],
            'too short' => [base64_encode(str_repeat('k', 31))],
            'too long' => [base64_encode(str_repeat('k', 33))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('corrupt_provider')]
    /**
     * A wrapped value that is not a key of 32 bytes is reported, and not replaced.
     *
     * @dataProvider corrupt_provider
     * @param string $inner What the wrapped value holds.
     */
    public function test_a_stored_value_that_is_not_a_key_is_reported(string $inner): void {
        $this->resetAfterTest();
        $stored = \core\encryption::encrypt($inner);
        set_config('dedupsecret', $stored, 'tool_secbackup');
        try {
            dedup_key::load_or_create();
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('dedupkey_corrupt', $e->errorcode);
            $this->assertNull($e->debuginfo);
        }
        $this->assertSame($stored, get_config('tool_secbackup', 'dedupsecret'));
    }

    public function test_unreadable_storage_is_reported_with_its_cause(): void {
        $this->resetAfterTest();
        $cases = [
            'encryption_wrongmethod' => 'not encrypted at all',
            'encryption_decryptfailed' => 'sodium:' . base64_encode(str_repeat("\x00", 64)),
        ];
        foreach ($cases as $cause => $stored) {
            set_config('dedupsecret', $stored, 'tool_secbackup');
            try {
                dedup_key::load_or_create();
                $this->fail('No exception');
            } catch (key_exception $e) {
                $this->assertSame('dedupkey_corrupt', $e->errorcode);
                $this->assertSame($cause, $e->debuginfo);
            }
        }
    }

    public function test_a_failed_encryption_is_reported_and_nothing_is_stored(): void {
        global $CFG;
        $this->resetAfterTest();
        // An empty folder for the key of the data root, and Moodle may not create one: encrypt() finds no key.
        $CFG->secretdataroot = make_request_directory();
        $CFG->nokeygeneration = true;
        $cause = null;
        try {
            \core\encryption::encrypt('probe');
        } catch (\moodle_exception $thrown) {
            $cause = $thrown::class;
        }
        $this->assertNotNull($cause, 'encrypt() has to fail for this test to mean anything');
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            dedup_key::load_or_create();
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('dedupkey_savefailed', $e->errorcode);
            $this->assertSame($cause, $e->debuginfo);
            // The exception of encrypt() holds the key in the arguments of its trace, so it is not chained.
            $this->assertNull($e->getPrevious());
            $this->assertNull($e->a);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
        $this->assertFalse(get_config('tool_secbackup', 'dedupsecret'));
    }
}
