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

use tool_secbackup\tests\envelope_builder;
use tool_secbackup\tests\fake_service;
use tool_secbackup\tests\standalone_scan;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the scan that keeps the fake service and its response signer free of Moodle and of the plugin.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class standalone_scan_test extends \basic_testcase {
    /**
     * The two files that must stand on their own.
     *
     * @return array
     */
    public static function standalone_class_provider(): array {
        return [
            'the fake service' => [fake_service::class],
            'the response signer' => [envelope_builder::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('standalone_class_provider')]
    /**
     * The development server loads these files without Moodle, and a test that uses the plugin's own classes to check the
     * plugin would pass even when both are wrong in the same way.
     *
     * @dataProvider standalone_class_provider
     * @param string $class The class whose file is read.
     */
    public function test_it_uses_nothing_of_the_plugin_or_of_moodle(string $class): void {
        $file = (new \ReflectionClass($class))->getFileName();
        $this->assertSame([], standalone_scan::problems((string) file_get_contents($file), basename($file)));
    }

    /**
     * Ways to use something that is not PHP's own, and the name that the scan has to report.
     *
     * @return array
     */
    public static function forbidden_use_provider(): array {
        return [
            'a Moodle function, fully qualified' => ["\\debugging('x');", '\debugging'],
            'a Moodle function, as the language falls back to it' => ["get_string('x');", 'get_string'],
            'a Moodle class in the global namespace' => ["new \\moodle_url('/');", '\moodle_url'],
            'a class of the plugin outside local' => ["\\tool_secbackup\\form\\register_form::class;",
                '\tool_secbackup\form\register_form'],
            'a class of the plugin inside local' => ["\\tool_secbackup\\local\\crypto\\fingerprint::of('x');",
                '\tool_secbackup\local\crypto\fingerprint'],
            'a helper class of the same namespace' => ["return network_fakes::HOST;", 'network_fakes'],
            'a class of Moodle after instanceof' => ["return \$this instanceof \\core\\output\\notification;",
                '\core\output\notification'],
            'a class of Moodle in a type' => ["\$f = static fn(\\moodle_url \$url): bool => true;", '\moodle_url'],
            'a class of Moodle in a catch' => [
                "try { return 1; } catch (\\moodle_exception \$e) { return 2; }",
                '\moodle_exception',
            ],
            'a function of the same namespace' => ["helper('x');", 'helper'],
            'a global variable of Moodle' => ["return \$GLOBALS['CFG'];", '$GLOBALS'],
            'the global statement' => ["global \$CFG;", 'global'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbidden_use_provider')]
    /**
     * A use that is planted in a copy of each file is found, with the file, the line and the name.
     *
     * @dataProvider forbidden_use_provider
     * @param string $code The statements that are planted.
     * @param string $name The name that is reported.
     */
    public function test_a_planted_use_is_found(string $code, string $name): void {
        foreach ([fake_service::class, envelope_builder::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $source = (string) file_get_contents($file);
            $line = substr_count($source, "\n") + 4;
            $planted = $source . "\nfinal class planted {\n    public function run() {\n" . $code . "\n    }\n}\n";
            $this->assertContains(
                basename($file) . ':' . $line . ' ' . $name,
                standalone_scan::problems($planted, basename($file)),
                $class
            );
        }
    }

    public function test_the_things_the_files_may_use_are_not_reported(): void {
        $code = "<?php\nnamespace tool_secbackup\\tests;\nuse GuzzleHttp\\Promise\\Create;\n"
            . "final class ok {\n    public function run(\\Psr\\Http\\Message\\RequestInterface \$request): string {\n"
            . "        \$a = new \\RuntimeException(json_encode([JSON_UNESCAPED_SLASHES, PHP_URL_PATH]));\n"
            . "        \$b = [fake_service::CODE, self::class, \\strlen('x'), Create::promiseFor(null), true];\n"
            . "        return \$this->run(\$request, flags: 1) . static::run2(\$request);\n    }\n}\n";
        $this->assertSame([], standalone_scan::problems($code, 'ok.php'));
    }
}
