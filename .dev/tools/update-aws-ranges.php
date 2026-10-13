<?php
// Rebuilds data/aws-ranges.json from the AWS IP address ranges and updates its version in thirdpartylibs.xml.
// Development only; not part of the plugin package. Usage: php .dev/tools/update-aws-ranges.php

$root = dirname(__DIR__, 2);
$source = 'https://ip-ranges.amazonaws.com/ip-ranges.json';
$json = file_get_contents($source);
$data = $json === false ? null : json_decode($json, true);
if (!is_array($data) || !isset($data['syncToken'], $data['createDate'], $data['prefixes'])) {
    fwrite(STDERR, "Could not read $source\n");
    exit(1);
}

$services = ['CLOUDFRONT' => [], 'S3' => []];
foreach ($data['prefixes'] as $prefix) {
    if ($prefix['service'] === 'CLOUDFRONT') {
        $services['CLOUDFRONT'][] = $prefix['ip_prefix'];
    } else if ($prefix['service'] === 'S3' && $prefix['region'] === 'ap-northeast-1') {
        $services['S3'][] = $prefix['ip_prefix'];
    }
}
foreach ($services as $name => $prefixes) {
    $prefixes = array_values(array_unique($prefixes));
    usort($prefixes, static function (string $a, string $b): int {
        [$neta, $bitsa] = explode('/', $a);
        [$netb, $bitsb] = explode('/', $b);
        return [ip2long($neta), (int) $bitsa] <=> [ip2long($netb), (int) $bitsb];
    });
    if (count($prefixes) < 5) {
        fwrite(STDERR, "Too few $name prefixes: " . count($prefixes) . "\n");
        exit(1);
    }
    $services[$name] = $prefixes;
}

$out = [
    'source' => $source,
    'syncToken' => (string) $data['syncToken'],
    'createDate' => (string) $data['createDate'],
    'services' => $services,
];
file_put_contents("$root/data/aws-ranges.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

$xmlfile = "$root/thirdpartylibs.xml";
$xml = file_get_contents($xmlfile);
$xml = preg_replace('~(<location>data/aws-ranges\.json</location>\s*<name>[^<]*</name>\s*<version>)[^<]*(</version>)~',
    '${1}' . $out['createDate'] . '${2}', $xml, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "Could not update the version in thirdpartylibs.xml\n");
    exit(1);
}
file_put_contents($xmlfile, $xml);

printf("CLOUDFRONT %d, S3 %d, createDate %s\n", count($services['CLOUDFRONT']), count($services['S3']), $out['createDate']);
