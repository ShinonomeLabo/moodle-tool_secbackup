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

namespace tool_secbackup\local\net;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\aws_ranges::class)]
/**
 * Tests for the AWS address ranges.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\aws_ranges
 */
final class aws_ranges_test extends \basic_testcase {
    /** @var string A small range list. */
    private const SAMPLE = '{"syncToken":"1791766626","createDate":"2026-10-12-00-57-06",'
        . '"services":{"CLOUDFRONT":["13.225.0.0/16","3.165.11.0/24","0.0.0.0/32"],"S3":["3.5.152.0/21"]}}';

    public function test_contains(): void {
        $ranges = aws_ranges::from_json(self::SAMPLE);
        $this->assertTrue($ranges->contains('13.225.158.68', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertTrue($ranges->contains('13.225.255.255', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('13.226.0.1', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertTrue($ranges->contains('3.165.11.77', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('3.165.12.1', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertTrue($ranges->contains('3.5.159.1', aws_ranges::SERVICE_S3));
        $this->assertFalse($ranges->contains('3.5.159.1', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('0.0.0.1', aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_only_ipv4_addresses_match(): void {
        $ranges = aws_ranges::from_json(self::SAMPLE);
        $this->assertFalse($ranges->contains('2600:9000::1', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('not an address', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains("13.225.158.68\n", aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_metadata(): void {
        $ranges = aws_ranges::from_json(self::SAMPLE);
        $this->assertSame('1791766626', $ranges->sync_token());
        $this->assertSame('2026-10-12-00-57-06', $ranges->create_date());
        $this->assertSame(3, $ranges->count(aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertSame(1, $ranges->count(aws_ranges::SERVICE_S3));
    }

    public function test_unknown_service_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        aws_ranges::from_json(self::SAMPLE)->contains('13.225.0.1', 'EC2');
    }

    /**
     * Range lists that are not valid.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        return [
            'not json' => ['nope'],
            'no services' => ['{"syncToken":"1","createDate":"x"}'],
            'missing S3' => ['{"syncToken":"1","createDate":"x","services":{"CLOUDFRONT":["13.225.0.0/16"]}}'],
            'prefix length 33' => ['{"syncToken":"1","createDate":"x","services":{"CLOUDFRONT":["13.225.0.0/33"],"S3":[]}}'],
            'bad address' => ['{"syncToken":"1","createDate":"x","services":{"CLOUDFRONT":["300.1.1.1/8"],"S3":[]}}'],
            'no prefix length' => ['{"syncToken":"1","createDate":"x","services":{"CLOUDFRONT":["13.225.0.0"],"S3":[]}}'],
            'line feed after a CLOUDFRONT prefix' => [
                '{"syncToken":"1","createDate":"x","services":{"CLOUDFRONT":["13.225.0.0/16\n"],"S3":[]}}',
            ],
            'line feed after an S3 prefix' => [
                '{"syncToken":"1","createDate":"x","services":{"CLOUDFRONT":[],"S3":["3.5.152.0/21\n"]}}',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * A range list that is not valid is a mistake of the caller.
     *
     * @dataProvider invalid_provider
     * @param string $json Range list.
     */
    public function test_invalid_lists_are_coding_errors(string $json): void {
        $this->expectException(\coding_exception::class);
        aws_ranges::from_json($json);
    }

    public function test_bundled_list_is_complete(): void {
        $ranges = aws_ranges::bundled();
        $this->assertGreaterThan(50, $ranges->count(aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertGreaterThan(5, $ranges->count(aws_ranges::SERVICE_S3));
        $this->assertMatchesRegularExpression('/^\d+\z/', $ranges->sync_token());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\z/', $ranges->create_date());
    }
}
