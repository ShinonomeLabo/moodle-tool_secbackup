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

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\signing_keypair::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\crypto\signing_keys::class)]
/**
 * Tests for the signing key pair and its storage.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\crypto\signing_keypair
 * @covers     \tool_secbackup\local\crypto\signing_keys
 */
final class signing_keys_test extends \advanced_testcase {
    public function test_generated_pair_signs_verifiably(): void {
        $pair = signing_keypair::generate();
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($pair->public_key()));
        $signature = $pair->sign('message');
        $this->assertTrue(sodium_crypto_sign_verify_detached($signature, 'message', $pair->public_key()));
    }

    public function test_debug_output_hides_the_secret(): void {
        $pair = signing_keypair::generate();
        ob_start();
        var_dump($pair);
        $dump = (string) ob_get_clean();
        $this->assertStringNotContainsString($pair->export_secret(), $dump);
        $this->assertStringNotContainsString(base64_encode($pair->export_secret()), $dump);
        $this->assertStringContainsString(base64_encode($pair->public_key()), $dump);
    }

    public function test_rejects_a_wrong_secret_length(): void {
        $this->expectException(\coding_exception::class);
        new signing_keypair('short');
    }

    public function test_the_secret_parameter_is_marked_sensitive(): void {
        $parameter = (new \ReflectionMethod(signing_keypair::class, '__construct'))->getParameters()[0];
        $this->assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));
    }

    public function test_nothing_is_stored_until_saved(): void {
        $this->resetAfterTest();
        signing_keypair::generate();
        $this->assertNull(signing_keys::load());
    }

    public function test_save_load_and_forget(): void {
        $this->resetAfterTest();
        $pair = signing_keypair::generate();
        signing_keys::save($pair);

        $stored = get_config('tool_secbackup', 'signingsecret');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(base64_encode($pair->export_secret()), $stored);

        $loaded = signing_keys::load();
        $this->assertNotNull($loaded);
        $this->assertSame($pair->public_key(), $loaded->public_key());

        signing_keys::forget();
        $this->assertNull(signing_keys::load());
    }

    public function test_corrupt_storage_is_reported(): void {
        $this->resetAfterTest();
        set_config('signingsecret', \core\encryption::encrypt(base64_encode('short')), 'tool_secbackup');
        try {
            signing_keys::load();
            $this->fail('No exception');
        } catch (key_exception $e) {
            $this->assertSame('signingkey_corrupt', $e->errorcode);
        }
    }

    public function test_unreadable_storage_is_reported_with_its_cause(): void {
        $this->resetAfterTest();
        // Not encrypted by Moodle at all, and encrypted with another key (the data root's key was lost).
        $cases = [
            'encryption_wrongmethod' => 'not encrypted at all',
            'encryption_decryptfailed' => 'sodium:' . base64_encode(str_repeat("\x00", 64)),
        ];
        foreach ($cases as $cause => $stored) {
            set_config('signingsecret', $stored, 'tool_secbackup');
            try {
                signing_keys::load();
                $this->fail('No exception');
            } catch (key_exception $e) {
                $this->assertSame('signingkey_corrupt', $e->errorcode);
                $this->assertSame($cause, $e->debuginfo);
            }
        }
    }
}
