# Mutation testing review

Infection runs on classes/local/{crypto,trust,api,net,endpoint} (.dev/infection/run.sh).
Gate: covered code MSI of at least 95%.

## Result (2026-10-17)

Infection 0.29.14 on Moodle 5.3, PostgreSQL, PHP 8.4, PHPUnit 11.5 (`.dev/infection/run.sh 503 pgsql`, 2 h 4 min):
1007 mutants (1032 before the 25 mutants of the ignore entries below were left out), 1004 killed, 3 escaped, no errors,
no time-outs, none uncovered. Covered code MSI 99.70%.

The 3 escaped mutants are the two rows marked *kept* below and the one that a Behat scenario kills.

## Escaped mutants that do not change the behaviour

The files of the plugin are not in strict mode (no `declare(strict_types=1)`), so PHP converts a scalar argument or return
value to the declared type the way a cast does: an int to its decimal digits, false to `''`, true to `'1'`. Several rows
rest on that.

Each row has an entry in the `mutators` part of `infection.json5` that ignores its mutator on its line of its method
(`Class::method::line`), except the rows marked *kept*: an entry there would also hide a mutant of the same mutator and
line that a test kills, so these mutants stay in the score as escaped. The line number ties an entry to one line: when the
code moves, the mutant comes back and is looked at again.

| File:line | Mutator | Why the behaviour is the same |
|---|---|---|
| classes/local/api/enrollment_request.php:78 | CastString | `get_config()` with a name returns a string or false, and the parameter `$pluginversion` is a string: false becomes `''`, as with the cast. |
| classes/local/api/enrollment_request.php:90 | CastString | `json_encode()` of a string with `JSON_INVALID_UTF8_SUBSTITUTE` cannot fail, and `json_decode()` of the JSON text of a string gives that string. Checked on 20,000 random byte strings: always a string. |
| classes/local/api/enrollment_request.php:91 | CastString | The text is valid UTF-8 (line 90), so `preg_replace()` with `/u` gives a string; the same check. |
| classes/local/api/request_signer.php:113 | CastString | `$timestamp` is an int and the parameter of `canonical()` a string: PHP writes the same decimal digits. |
| classes/local/crypto/base64url.php:46 | PregMatchRemoveCaret | A text that the pattern without `^` accepts and the full pattern refuses holds a character outside the alphabet before its last run of alphabet characters. `sodium_base642bin()`, given no characters to ignore, throws for every such character, and `decode()` returns null either way. Checked with all 256 byte values before, between and after alphabet characters: sodium accepted none. |
| classes/local/crypto/fingerprint.php:57 | CastString | The pattern `/\s+/` has no `/u`, so no input is invalid for it, and it neither backtracks nor recurses: `preg_replace()` gives a string for every input (also for 12 MB of white space). |
| classes/local/crypto/signing_keys.php:70 | FalseValue | When `base64_decode()` gives false, `$secret === true` is false, but `strlen(false)` is 0, not 64: the same exception. A string is never `true`. |
| classes/local/endpoint/endpoint.php:96 | DecrementInteger (`isset($m[5])` to `isset($m[4])`), *kept* | `preg_match()` fills `$m[4]` only when group 4 or a later group took part. Group 4 starts with `?`, so it is not empty when it took part, and `$m[4]` is `''` only when group 5 took part. So `isset($m[4])` is true exactly when the original condition is. Not ignored: the mutant `$m[4] !== ''` to `$m[3] !== ''` on the same line is a different behaviour, which "query without a path" of endpoint_test kills. |
| classes/local/net/aws_ranges.php:64 | CastString | `file_get_contents()` gives a string or false (with the same warning either way), and the parameter of `from_json()` is a string: false becomes `''`, as with the cast. |
| classes/local/net/aws_ranges.php:113 | CastInt | `$m[2]` matched `[89]\|[12][0-9]\|3[0-2]`, a decimal integer, which PHP subtracts as that integer without a notice. |
| classes/local/net/doh_resolver.php:99 | FalseValue (`http_errors` true) | With `http_errors`, Guzzle throws a RequestException for a 4xx or 5xx answer, and the catch makes the same `transport_exception('dns_doh_failed', <resolver>)` that the check of the status makes for every status but 200. The option does not change other answers. |
| classes/local/net/doh_resolver.php:127 | IncrementInteger | `read(MAX + 2)` instead of `read(MAX + 1)`: a body of more than MAX bytes still gives more than MAX bytes and is refused, and a shorter body is read whole. The body is a `php://temp` stream (the default sink of Guzzle, and the body of a mocked answer), whose `read()` gives as many bytes as are asked for while there are any. |
| classes/local/net/transport.php:133 | CastString | An array key is an int or a string, and the parameter of `strtolower()` a string: an int key becomes the same digits. |
| classes/local/net/transport.php:201 | IncrementInteger | `Utils::copyToString()` reads until the length or the end of the stream: with MAX + 2 instead of MAX + 1, exactly the same bodies give more than MAX bytes. |
| classes/local/net/transport.php:222 | DecrementInteger (`$errno = -1`) | The value stays only for a failure that has no handler context. -1 is, like 0, not above 0 and in neither list of error numbers, so the detail (the class name) and the kind of failure are the same. |
| classes/local/net/transport.php:224 | DecrementInteger (`?? -1`) | The same, for a handler context without an error number. |
| classes/local/net/transport.php:244 | UnwrapTrim (both `trim()` calls) | The value is a header line of a Guzzle response. Guzzle trims spaces and tabs from header values and refuses every other character that `trim()` removes (CR, LF, NUL, vertical tab; checked), so `trim()` gives the value unchanged. |
| classes/local/net/transport.php:244 | CastInt | The value is 1 to 6 digits: `min()` compares it as a number, and the return type `?int` turns the digits into the same integer. |
| classes/local/trust/developer_settings.php:124 | FalseValue (`: true`) | For a key that is not a string, `$raw` is true: `true === false` is false, but `strlen(true)` is 1, not 32: the same exception. |
| classes/local/trust/developer_settings.php:127 | FalseValue (`$raw === true`) | When `base64_decode()` gives false, `strlen(false)` is 0, not 32: the same exception. `$raw` is never `true` here. |
| classes/local/trust/sec_trust.php:67 | CastString | The function runs once for each embedded key, and there is none yet (`EMBEDDED_KEYS` is empty until the release plan fills it). Even then, its return type string turns false into `''`, as the cast does. |
| classes/local/trust/sec_trust.php:67 | UnwrapArrayMap | `EMBEDDED_KEYS` is empty, so the map gives the same empty list. When keys are embedded, this mutant changes the behaviour: add a test that `current()` trusts them, and remove the entry. |
| classes/local/trust/sec_trust.php:68 | UnwrapArrayMerge (to the developer keys alone), *kept* | The same: merging the empty list of embedded keys changes nothing. The same note. Not ignored: the mutator also makes the mutant that keeps the embedded keys alone, on the same line, which drops the developer keys and which sec_trust_test kills. |
| classes/local/trust/sec_trust.php:88 | CastString | The return type string turns an int key into the same digits. |
| classes/local/trust/sec_trust.php:102 | DecrementInteger (depth 3) | `well_formed()` accepts only an object whose four values are an integer and three strings. Every body with a nested object or list is refused as `envelope_malformed`, by `json_decode()` or by `well_formed()`, with the same exception; a depth of 3 or 4 decodes every body without one. |
| classes/local/trust/sec_trust.php:102 | IncrementInteger (depth 5) | The same, for a depth of 5. |

## Escaped mutants that a Behat scenario kills

Infection runs PHPUnit only, and PHPUnit always runs with `PHPUNIT_TEST` true.

| File:line | Mutator | Test |
|---|---|---|
| classes/local/trust/developer_settings.php:76 | LogicalAnd (`defined('PHPUNIT_TEST') && PHPUNIT_TEST` to `\|\|`) | Outside PHPUnit, Moodle defines `PHPUNIT_TEST` as false, so the mutant reads the developer settings from `$CFG`, which the database and the caches can write. `tests/behat/developer_settings.feature` puts settings there and fails on the mutant (applied by hand and run on 5.3 PostgreSQL). |
