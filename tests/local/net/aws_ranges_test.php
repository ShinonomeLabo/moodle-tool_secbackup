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

use tool_secbackup\local\api\instruction;
use tool_secbackup\local\trust\envelope_exception;
use tool_secbackup\tests\instruction_factory;

#[\PHPUnit\Framework\Attributes\CoversClass(\tool_secbackup\local\net\aws_ranges::class)]
/**
 * Tests for the AWS address ranges.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_secbackup\local\net\aws_ranges
 */
final class aws_ranges_test extends \advanced_testcase {
    /** @var string[] Five CloudFront prefixes: the fewest that a list may have for a service. */
    private const CLOUDFRONT = ['13.225.0.0/16', '3.165.11.0/24', '0.0.0.0/32', '18.64.0.0/14', '64.252.64.0/18'];

    /** @var string[] Five S3 prefixes. */
    private const S3 = ['3.5.152.0/21', '16.15.54.0/23', '52.219.0.0/20', '52.219.16.0/22', '35.72.164.240/28'];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

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

    public function test_the_region_of_the_bundled_list_is_tokyo(): void {
        $this->assertSame('ap-northeast-1', aws_ranges::bundled()->s3_region());
        $this->assertSame('ap-northeast-1', aws_ranges::from_json(self::sample())->s3_region());
    }

    public function test_the_region_of_a_list_is_read(): void {
        $this->assertSame('us-east-1', aws_ranges::from_json(self::with_part('s3_region', 'us-east-1'))->s3_region());
    }

    /**
     * Regions that are not a region name.
     *
     * @return array[]
     */
    public static function invalid_regions(): array {
        return [
            'a number' => [5],
            'null' => [null],
            'empty' => [''],
            'no number' => ['ap-northeast-'],
            'two digits' => ['ap-northeast-12'],
            'capitals' => ['AP-NORTHEAST-1'],
            'a final line feed' => ["ap-northeast-1\n"],
            'a list' => [['ap-northeast-1']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_regions')]
    /**
     * A region that is not a region name is refused.
     *
     * @dataProvider invalid_regions
     * @param mixed $region The region.
     */
    public function test_an_invalid_region_is_refused(mixed $region): void {
        $this->expectException(\coding_exception::class);
        aws_ranges::from_json(self::with_part('s3_region', $region));
    }

    public function test_repeated_prefixes_are_counted_once(): void {
        $repeated = ['13.225.0.0/16', '13.225.0.0/16', '3.165.11.0/24', '3.165.11.0/24', '18.64.0.0/14'];
        try {
            aws_ranges::from_json(self::with_service('CLOUDFRONT', $repeated));
            $this->fail('No exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('too few prefixes of CLOUDFRONT.', $e->getMessage());
        }
        $six = array_merge(self::CLOUDFRONT, [self::CLOUDFRONT[0]]);
        $this->assertSame(5, aws_ranges::from_json(self::with_service('CLOUDFRONT', $six))->count('CLOUDFRONT'));
    }

    /**
     * An aws_ranges instruction for a content.
     *
     * @param int $seq Sequence number.
     * @param string $content The content.
     * @param array $changes Fields of the body to replace.
     * @return instruction
     */
    private static function instruction(int $seq, string $content, array $changes = []): instruction {
        return instruction_factory::make('aws_ranges', $seq, array_merge([
            'sync_token' => '1791766626', 'create_date' => '2026-10-18-00-00-00',
            'sha256' => hash('sha256', $content), 'size' => strlen($content),
        ], $changes));
    }

    public function test_the_bundled_list_is_current_when_nothing_is_stored(): void {
        $this->assertSame(aws_ranges::bundled()->sync_token(), aws_ranges::current()->sync_token());
        $this->assertSame(0, aws_ranges::stored_seq());
    }

    public function test_a_stored_list_is_preferred_to_the_bundled_one(): void {
        $content = self::sample();
        $this->assertTrue(aws_ranges::is_newer(self::instruction(3, $content)));
        $this->assertTrue(aws_ranges::apply(self::instruction(3, $content), $content));
        $this->assertSame('1791766626', aws_ranges::current()->sync_token());
        $this->assertSame(5, aws_ranges::current()->count('S3'));
        $this->assertSame(3, aws_ranges::stored_seq());
        $this->assertSame($content, get_config('tool_secbackup', 'awsranges'));
    }

    public function test_an_older_or_equal_seq_is_not_newer_and_changes_nothing(): void {
        $content = self::sample();
        aws_ranges::apply(self::instruction(3, $content), $content);
        $other = self::with_part('syncToken', '999');
        $this->assertFalse(aws_ranges::is_newer(self::instruction(3, $other)));
        $this->assertFalse(aws_ranges::apply(self::instruction(3, $other), $other));
        $this->assertFalse(aws_ranges::apply(self::instruction(2, $other), $other));
        $this->assertSame('1791766626', aws_ranges::current()->sync_token());
        $this->assertTrue(aws_ranges::is_newer(self::instruction(4, $other)));
    }

    public function test_a_content_that_differs_from_the_signed_hash_or_size_is_not_stored(): void {
        $content = self::sample();
        $other = self::with_part('syncToken', '999');
        $this->assertFalse(aws_ranges::apply(self::instruction(3, $content), $other));
        $samesize = str_replace('1791766626', '1791766627', $content);
        $this->assertSame(strlen($content), strlen($samesize));
        $this->assertFalse(aws_ranges::apply(self::instruction(3, $content), $samesize));
        $this->assertFalse(aws_ranges::apply(self::instruction(3, $content, ['size' => strlen($content) + 1]), $content));
        $this->assertFalse(get_config('tool_secbackup', 'awsranges'));
        $this->assertSame(0, aws_ranges::stored_seq());
    }

    public function test_a_content_that_is_not_a_list_is_refused_even_when_signed(): void {
        $junk = '{"syncToken":"1"}';
        try {
            aws_ranges::apply(self::instruction(3, $junk), $junk);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('instruction_invalid', $e->errorcode);
        }
        $this->assertFalse(get_config('tool_secbackup', 'awsranges'));
        $this->assertSame(0, aws_ranges::stored_seq());
    }

    /**
     * Bodies of an aws_ranges instruction that break a rule.
     *
     * @return array[]
     */
    public static function invalid_bodies(): array {
        $good = ['sync_token' => '1791766626', 'create_date' => '2026-10-18-00-00-00', 'sha256' => str_repeat('a', 64),
            'size' => 100];
        return [
            'no token' => [array_diff_key($good, ['sync_token' => 1])],
            'a token that is a number' => [array_merge($good, ['sync_token' => 5])],
            'an empty token' => [array_merge($good, ['sync_token' => ''])],
            'a long token' => [array_merge($good, ['sync_token' => str_repeat('1', 65)])],
            'a date of another form' => [array_merge($good, ['create_date' => '2026-10-18'])],
            'a date with a final line feed' => [array_merge($good, ['create_date' => "2026-10-18-00-00-00\n"])],
            'a hash in capitals' => [array_merge($good, ['sha256' => str_repeat('A', 64)])],
            'a short hash' => [array_merge($good, ['sha256' => str_repeat('a', 63)])],
            'a hash with a final line feed' => [array_merge($good, ['sha256' => str_repeat('a', 64) . "\n"])],
            'a size of zero' => [array_merge($good, ['size' => 0])],
            'a size larger than a response' => [array_merge($good, ['size' => 1048577])],
            'a size as text' => [array_merge($good, ['size' => '100'])],
            'an extra field' => [array_merge($good, ['extra' => 1])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_bodies')]
    /**
     * A body that breaks a rule is refused.
     *
     * @dataProvider invalid_bodies
     * @param array $body Body.
     */
    public function test_invalid_bodies_are_refused(array $body): void {
        $i = instruction_factory::make('aws_ranges', 3, $body);
        try {
            aws_ranges::is_newer($i);
            $this->fail('No exception');
        } catch (envelope_exception $e) {
            $this->assertSame('instruction_invalid', $e->errorcode);
        }
        $this->expectException(envelope_exception::class);
        aws_ranges::apply($i, 'x');
    }

    public function test_an_instruction_of_another_kind_is_a_coding_error(): void {
        $i = instruction_factory::make('settings', 3, instruction_factory::settings_body());
        $this->expectException(\coding_exception::class);
        aws_ranges::is_newer($i);
    }

    public function test_a_stored_list_that_cannot_be_read_is_dropped_and_the_bundled_list_is_used(): void {
        set_config('awsranges', 'garbage', 'tool_secbackup');
        set_config('awsrangesseq', '7', 'tool_secbackup');
        $this->assertSame(aws_ranges::bundled()->sync_token(), aws_ranges::current()->sync_token());
        $this->assertFalse(get_config('tool_secbackup', 'awsranges'));
        $this->assertSame(0, aws_ranges::stored_seq());
        $this->assertFalse(get_config('tool_secbackup', 'awsrangesseq'));
    }

    public function test_a_stored_list_belongs_to_the_connection_url_it_came_from(): void {
        $content = self::sample();
        set_config('endpoint', 'https://app.example.test/api/lms-backup/v1', 'tool_secbackup');
        aws_ranges::apply(self::instruction(9, $content), $content);
        $this->assertSame('1791766626', aws_ranges::current()->sync_token());

        set_config('endpoint', 'https://other.example.test/api/lms-backup/v1', 'tool_secbackup');
        $this->assertSame(aws_ranges::bundled()->sync_token(), aws_ranges::current()->sync_token());
        $this->assertSame(0, aws_ranges::stored_seq());
        $this->assertTrue(aws_ranges::is_newer(self::instruction(1, $content)));
        $other = self::with_part('syncToken', '555');
        $this->assertTrue(aws_ranges::apply(self::instruction(1, $other), $other));
        $this->assertSame('555', aws_ranges::current()->sync_token());
        $this->assertSame(1, aws_ranges::stored_seq());
    }

    public function test_removing_the_registration_removes_the_stored_list(): void {
        $content = self::sample();
        aws_ranges::apply(self::instruction(9, $content), $content);
        \tool_secbackup\local\enrollment\state::clear();
        foreach (['awsranges', 'awsrangesseq', 'awsrangesendpoint'] as $name) {
            $this->assertFalse(get_config('tool_secbackup', $name), $name);
        }
        $this->assertSame(aws_ranges::bundled()->sync_token(), aws_ranges::current()->sync_token());
    }
}
