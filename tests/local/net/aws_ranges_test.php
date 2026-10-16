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
    /** @var string[] Five CloudFront prefixes: the fewest that a list may have for a service. */
    private const CLOUDFRONT = ['13.225.0.0/16', '3.165.11.0/24', '0.0.0.0/32', '18.64.0.0/14', '64.252.64.0/18'];

    /** @var string[] Five S3 prefixes. */
    private const S3 = ['3.5.152.0/21', '16.15.54.0/23', '52.219.0.0/20', '52.219.16.0/22', '35.72.164.240/28'];

    /**
     * A valid range list, as the array that data/aws-ranges.json decodes to.
     *
     * @return array
     */
    private static function valid(): array {
        return [
            'syncToken' => '1791766626',
            'createDate' => '2026-10-12-00-57-06',
            'services' => ['CLOUDFRONT' => self::CLOUDFRONT, 'S3' => self::S3],
        ];
    }

    /**
     * The valid range list as JSON.
     *
     * @return string
     */
    private static function sample(): string {
        return json_encode(self::valid());
    }

    /**
     * The valid range list with one part replaced.
     *
     * @param string $name syncToken, createDate or services.
     * @param mixed $value The new value.
     * @return string
     */
    private static function with_part(string $name, mixed $value): string {
        $data = self::valid();
        $data[$name] = $value;
        return json_encode($data);
    }

    /**
     * The valid range list without one part.
     *
     * @param string $name syncToken, createDate or services.
     * @return string
     */
    private static function without_part(string $name): string {
        $data = self::valid();
        unset($data[$name]);
        return json_encode($data);
    }

    /**
     * The valid range list with the list of one service replaced.
     *
     * @param string $service CLOUDFRONT or S3.
     * @param mixed $list The new value.
     * @return string
     */
    private static function with_service(string $service, mixed $list): string {
        $data = self::valid();
        $data['services'][$service] = $list;
        return json_encode($data);
    }

    /**
     * The valid range list without the list of one service.
     *
     * @param string $service CLOUDFRONT or S3.
     * @return string
     */
    private static function without_service(string $service): string {
        $data = self::valid();
        unset($data['services'][$service]);
        return json_encode($data);
    }

    /**
     * The valid range list with the last prefix of one service replaced.
     *
     * @param string $service CLOUDFRONT or S3.
     * @param mixed $prefix The new value.
     * @return string
     */
    private static function with_last_prefix(string $service, mixed $prefix): string {
        $prefixes = self::valid()['services'][$service];
        $prefixes[count($prefixes) - 1] = $prefix;
        return self::with_service($service, $prefixes);
    }

    public function test_contains(): void {
        $ranges = aws_ranges::from_json(self::sample());
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
        $ranges = aws_ranges::from_json(self::sample());
        $this->assertFalse($ranges->contains('2600:9000::1', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('not an address', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains("13.225.158.68\n", aws_ranges::SERVICE_CLOUDFRONT));
        // The function ip2long() would throw a ValueError for a null byte; the address is refused before it.
        $this->assertFalse($ranges->contains("13.225.158.68\0", aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_text_that_is_not_an_address_is_in_no_range_even_one_that_starts_at_zero(): void {
        // A range of network 0 would contain whatever number stood in for a refused address.
        $ranges = aws_ranges::from_json(self::with_last_prefix('CLOUDFRONT', '0.0.0.0/8'));
        $this->assertTrue($ranges->contains('0.1.2.3', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('not an address', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('2600:9000::1', aws_ranges::SERVICE_CLOUDFRONT));
    }

    public function test_metadata(): void {
        $ranges = aws_ranges::from_json(self::sample());
        $this->assertSame('1791766626', $ranges->sync_token());
        $this->assertSame('2026-10-12-00-57-06', $ranges->create_date());
        $this->assertSame(5, $ranges->count(aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertSame(5, $ranges->count(aws_ranges::SERVICE_S3));
    }

    public function test_unknown_service_is_a_coding_error(): void {
        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Unknown AWS service: EC2');
        aws_ranges::from_json(self::sample())->contains('13.225.0.1', 'EC2');
    }

    public function test_the_shortest_accepted_prefix_is_a_slash_8(): void {
        $ranges = aws_ranges::from_json(self::with_last_prefix('CLOUDFRONT', '10.0.0.0/8'));
        $this->assertTrue($ranges->contains('10.0.0.0', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertTrue($ranges->contains('10.255.255.255', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('9.255.255.255', aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertFalse($ranges->contains('11.0.0.0', aws_ranges::SERVICE_CLOUDFRONT));
    }

    /**
     * Range lists that are not valid, each in one way only, with a part of the message that tells the rule it broke.
     *
     * @return array
     */
    public static function invalid_provider(): array {
        $objectoflist = array_combine(['a', 'b', 'c', 'd', 'e'], self::CLOUDFRONT);
        return [
            'not json' => ['nope', 'is not valid'],
            'a list instead of an object' => ['[]', 'is not valid'],
            'no syncToken' => [self::without_part('syncToken'), 'is not valid'],
            'syncToken is a number' => [self::with_part('syncToken', 1791766626), 'is not valid'],
            'syncToken is a list' => [self::with_part('syncToken', ['1791766626']), 'is not valid'],
            'no createDate' => [self::without_part('createDate'), 'is not valid'],
            'createDate is null' => [self::with_part('createDate', null), 'is not valid'],
            'createDate is a number' => [self::with_part('createDate', 20261012), 'is not valid'],
            'no services' => [self::without_part('services'), 'is not valid'],
            'services is a string' => [self::with_part('services', 'CLOUDFRONT'), 'is not valid'],
            'no CLOUDFRONT list' => [self::without_service('CLOUDFRONT'), 'is not valid'],
            'no S3 list' => [self::without_service('S3'), 'is not valid'],
            'CLOUDFRONT is a string' => [self::with_service('CLOUDFRONT', '13.225.0.0/16'), 'is not valid'],
            'S3 is a number' => [self::with_service('S3', 5), 'is not valid'],
            'CLOUDFRONT is an object' => [self::with_service('CLOUDFRONT', $objectoflist), 'is not valid'],
            'four CLOUDFRONT prefixes' => [
                self::with_service('CLOUDFRONT', array_slice(self::CLOUDFRONT, 0, 4)),
                'too few prefixes of CLOUDFRONT.',
            ],
            'four S3 prefixes' => [self::with_service('S3', array_slice(self::S3, 0, 4)), 'too few prefixes of S3.'],
            'no CLOUDFRONT prefixes' => [self::with_service('CLOUDFRONT', []), 'too few prefixes of CLOUDFRONT.'],
            'no S3 prefixes' => [self::with_service('S3', []), 'too few prefixes of S3.'],
            'prefix length 33' => [self::with_last_prefix('CLOUDFRONT', '13.225.0.0/33'), 'invalid prefix'],
            'prefix length 7' => [self::with_last_prefix('CLOUDFRONT', '10.0.0.0/7'), 'invalid prefix'],
            'prefix length 0' => [self::with_last_prefix('S3', '0.0.0.0/0'), 'invalid prefix'],
            'bad address' => [self::with_last_prefix('CLOUDFRONT', '300.1.1.1/8'), 'invalid prefix'],
            'no prefix length' => [self::with_last_prefix('CLOUDFRONT', '13.225.0.0'), 'invalid prefix'],
            'letters before a prefix' => [self::with_last_prefix('CLOUDFRONT', 'x13.225.0.0/16'), 'invalid prefix'],
            'line feed after a CLOUDFRONT prefix' => [self::with_last_prefix('CLOUDFRONT', "13.225.0.0/16\n"), 'invalid prefix'],
            'line feed after an S3 prefix' => [self::with_last_prefix('S3', "3.5.152.0/21\n"), 'invalid prefix'],
            'a number as a prefix' => [self::with_last_prefix('CLOUDFRONT', 5), 'invalid prefix'],
            'null as a prefix' => [self::with_last_prefix('S3', null), 'invalid prefix'],
            'a list as a prefix' => [self::with_last_prefix('S3', ['3.5.152.0/21']), 'invalid prefix'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_provider')]
    /**
     * A range list that is not valid is a mistake of the caller, and the message tells which rule the list broke.
     *
     * @dataProvider invalid_provider
     * @param string $json Range list.
     * @param string $reason Part of the message.
     */
    public function test_invalid_lists_are_coding_errors(string $json, string $reason): void {
        try {
            aws_ranges::from_json($json);
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function test_bundled_list_is_complete(): void {
        $ranges = aws_ranges::bundled();
        $this->assertGreaterThan(50, $ranges->count(aws_ranges::SERVICE_CLOUDFRONT));
        $this->assertGreaterThan(5, $ranges->count(aws_ranges::SERVICE_S3));
        $this->assertMatchesRegularExpression('/^\d+\z/', $ranges->sync_token());
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}\z/', $ranges->create_date());
    }
}
