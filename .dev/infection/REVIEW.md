# Mutation testing review

Infection runs on classes/local/{crypto,trust,api,net,endpoint}, with the configuration `.dev/infection/infection.json5`:
on GitHub with the manual workflow `.github/workflows/infection.yml`, which splits the files into the shards of
`.dev/infection/shards.txt` (one job each) and adds the shards up in a last job (`.dev/infection/merge.php`), or on a
development machine with `.dev/infection/run.sh` (one process for all files, which takes several hours since version 0.2.0).
Gate: covered code MSI of at least 95%, over all shards together.

## Result (version 0.2.0)

Run 39598944945 of the workflow Infection (2026-10-21, on commit 930df33; Infection 0.29.14 on Moodle 5.3+, PostgreSQL 17,
PHP 8.4, PHPUnit 11.5; ten shards of 17 to 48 minutes each): 2392 mutants (after the ignore entries below), 2386 killed,
6 escaped, no errors, no time-outs, none uncovered, none skipped. Covered code MSI 99.75%.

The 6 escaped mutants are the four rows marked *kept* below, the one that a Behat scenario kills and the one that PHPUnit
kills outside the tests that Infection runs for it. Every other mutant that escaped the first run of version 0.2.0 (run
39586331895: 2420 mutants, 127 escaped, 94.75%) is killed by a test added after it or is a row below; that run also found
that an empty stored list of AWS ranges kept its sequence number (fixed in aws_ranges::current()).

## Result (version 0.1.0, 2026-10-17)

Infection 0.29.14 on Moodle 5.3, PostgreSQL, PHP 8.4, PHPUnit 11.5 (`.dev/infection/run.sh 503 pgsql`, 2 h 4 min):
1007 mutants (1032 before the 25 mutants of the ignore entries below were left out), 1004 killed, 3 escaped, no errors,
no time-outs, none uncovered. Covered code MSI 99.70%.

## Escaped mutants that do not change the behaviour

The files of the plugin are not in strict mode (no `declare(strict_types=1)`), so PHP converts a scalar argument or return
value to the declared type the way a cast does: an int to its decimal digits, false to `''`, true to `'1'`. Several rows
rest on that.

Each row has an entry in the `mutators` part of `infection.json5` that ignores its mutator on its line of its method
(`Class::method::line`), except the rows marked *kept*: there an entry would also hide a mutant of the same mutator and
line that a test kills, or the reason rests on how a library works inside, which a later version may change; so these
mutants stay in the score as escaped. The line number ties an entry to one line: when the
code moves, the mutant comes back and is looked at again.

| File:line | Mutator | Why the behaviour is the same |
|---|---|---|
| classes/local/api/client.php:370 | UnwrapArrayValues | `upload_request()` refused the items already unless they are a list (`array_is_list()`), so `array_values()` gives the same array. |
| classes/local/api/client.php:673 | CastString | The pattern before lets through only 43 characters of the base64 alphabet and `=`, which `base64_decode()` in strict mode always decodes to a string, never to false. |
| classes/local/api/client.php:707 | Concat (both), CastString | The key only finds an item that is asked for twice. The kinds are `object`, `manifest` and `index`, none the start of another, and the name of an object always has 64 characters (the others have none: null, which becomes `''` with or without the cast). So two keys are the same exactly when the kind and the name are, in either order and with the separator anywhere. |
| classes/local/api/client.php:707 | ConcatOperandRemoval (without the separator), *kept* | The same. Not ignored: removing the kind or the name is a different behaviour (two items that differ would have the same key), which the requests of client_runs_test with two objects, or with the manifest and the index, kill. |
| classes/local/api/client.php:711 | TrueValue | The check before is `isset()`, which is true for a stored false as for a stored true. |
| classes/local/api/client.php:779 | CastString | An array key is an int or a string, and the parameter of `strtolower()` a string: an int key becomes the same digits. |
| classes/local/api/enrollment_request.php:78 | CastString | `get_config()` with a name returns a string or false, and the parameter `$pluginversion` is a string: false becomes `''`, as with the cast. |
| classes/local/api/enrollment_request.php:90 | CastString | `json_encode()` of a string with `JSON_INVALID_UTF8_SUBSTITUTE` cannot fail, and `json_decode()` of the JSON text of a string gives that string. Checked on 20,000 random byte strings: always a string. |
| classes/local/api/enrollment_request.php:91 | CastString | `preg_replace()` gives null only for text that is not valid UTF-8 under `/u`, or when the match runs into a limit of backtracking, recursion or the JIT stack. The text is valid UTF-8 (line 90), and the pattern `/\p{C}/u` is a class of one character without a quantifier, so it neither backtracks nor recurses: `preg_replace()` gives a string. The same check. |
| classes/local/api/request_signer.php:120 | CastString | `$timestamp` is an int and the parameter of `canonical()` a string: PHP writes the same decimal digits. |
| classes/local/crypto/base64url.php:46 | PregMatchRemoveCaret | A text that the pattern without `^` accepts and the full pattern refuses holds a character outside the alphabet before its last run of alphabet characters. `sodium_base642bin()`, given no characters to ignore, throws for every such character, and `decode()` returns null either way. Checked with all 256 byte values before, between and after alphabet characters: sodium accepted none. |
| classes/local/crypto/fingerprint.php:57 | CastString | The pattern `/\s+/` has no `/u`, so no input is invalid for it, and it neither backtracks nor recurses: `preg_replace()` gives a string for every input (also for 12 MB of white space). |
| classes/local/crypto/padme.php:41 | LessThan (`$length < 2` to `<= 2`) | The mutant returns 2 for 2 without the formula, and the formula gives 2 for 2 as well: E = floor(log2 2) = 1, S = floor(log2 1) + 1 = 1, so no bit is cleared. Every length up to 8 is its own padding (padme_test). |
| classes/local/crypto/signing_keys.php:70 | FalseValue | When `base64_decode()` gives false, `$secret === true` is false, but `strlen(false)` is 0, not 64: the same exception. A string is never `true`. |
| classes/local/endpoint/endpoint.php:96 | DecrementInteger (`isset($m[5])` to `isset($m[4])`), *kept* | `preg_match()` fills `$m[4]` only when group 4 or a later group took part. Group 4 starts with `?`, so it is not empty when it took part, and `$m[4]` is `''` only when group 5 took part. So `isset($m[4])` is true exactly when the original condition is. Not ignored: the mutant `$m[4] !== ''` to `$m[3] !== ''` on the same line is a different behaviour, which "query without a path" of endpoint_test kills. |
| classes/local/net/aws_ranges.php:81 | CastString | `file_get_contents()` gives a string or false (with the same warning either way), and the parameter of `from_json()` is a string: false becomes `''`, as with the cast. |
| classes/local/net/aws_ranges.php:120 | CastInt | `$seq` matched `^[1-9]\d{0,17}\z`, at most 18 digits, below the largest int: the return type int turns the digits into the same integer. |
| classes/local/net/aws_ranges.php:129 | CastString | `get_config()` with a name gives a string or false, and the return type string turns false into `''`, as the cast does. |
| classes/local/net/aws_ranges.php:138 | CastString | The same. |
| classes/local/net/aws_ranges.php:240 | Concat (both) | The key only makes a network count once. It joins the address of the network and its mask, both numbers, and the mask of every prefix from /8 to /32 is a number of ten digits (4278190080 to 4294967295). So the two numbers can be read back from the key in either order and with the slash anywhere: two keys are the same exactly when the networks are. |
| classes/local/net/aws_ranges.php:240 | ConcatOperandRemoval (without the slash), *kept* | The same. Not ignored: removing the address or the mask is a different behaviour (networks would count once that differ), which aws_ranges_test kills. |
| classes/local/net/aws_ranges.php:245 | UnwrapArrayValues | The lists of networks are only walked with `foreach` and counted (`contains()`, `count()`); their keys are never read. |
| classes/local/net/aws_ranges.php:266 | CastInt | `$m[2]` matched `[89]\|[12][0-9]\|3[0-2]`, a decimal integer, which PHP subtracts as that integer without a notice. |
| classes/local/net/doh_resolver.php:99 | FalseValue (`http_errors` true) | With `http_errors`, Guzzle throws a RequestException for a 4xx or 5xx answer, and the catch makes the same `transport_exception('dns_doh_failed', <resolver>)` that the check of the status makes for every status but 200. The option does not change other answers. |
| classes/local/net/doh_resolver.php:127 | IncrementInteger | `read(MAX + 2)` instead of `read(MAX + 1)`: a body of more than MAX bytes still gives more than MAX bytes and is refused, and a shorter body is read whole. The body is a `php://temp` stream (the default sink of Guzzle, and the body of a mocked answer), whose `read()` gives as many bytes as are asked for while there are any. |
| classes/local/net/guarded_options.php:112 | DecrementInteger (`$errno = -1`) | The value stays only for a failure that has no handler context. -1 is, like 0, not above 0 and in neither list of error numbers, so the detail (the class name) and the kind of failure are the same. |
| classes/local/net/guarded_options.php:114 | DecrementInteger (`?? -1`) | The same, for a handler context without an error number. |
| classes/local/net/object_transport.php:169 | DecrementInteger (a speed limit of -1 for a GET) | `request()` reads the limit only as `$limit > 0`: -1, like 0, means no limit. The speed option of curl is set by `put_file()` only. |
| classes/local/net/object_transport.php:174 | FunctionCallRemoval (`clearstatcache()`), *kept* | PHP keeps the stat of the last path that it looked at only, and between the start of the request and `filesize()` other paths are looked at: Guzzle's curl handler checks the folder of the sink with `is_dir()` before the transfer (`CurlFactory::applyHandlerOptions()`), and the requests of the tests do the same through Moodle's HTTP client. So `filesize()` reads the file again either way; a test that read the size of the file before the download passed with the mutant. Not ignored: that rests on what Guzzle does inside, which a later version may change; the call stays so that the result does not depend on it. |
| classes/local/net/object_transport.php:175 | CastInt | `filesize()` gives false only for a file that is not there, and a GET that succeeded always leaves its file: Guzzle's curl handler opens the sink (mode `w+`) when it rewinds the body at the end (`CurlFactory::finish()`), also for an empty body, and the mock writes it with `file_put_contents()`. For an int the cast does nothing. |
| classes/local/net/object_transport.php:202 | CastString | As client.php:779. |
| classes/local/net/object_transport.php:217 | CastInt | The value matched `^(?:0\|[1-9][0-9]{0,15})\z`; the return type int turns the digits into the same number. |
| classes/local/net/object_transport.php:228 | CastString | As client.php:779. |
| classes/local/net/object_transport.php:331 | GreaterThanOrEqualTo (`>= 300` to `> 300`) | A status of 300 never comes to this line: the line before throws `transport_redirect` for every status from 300 to 399. |
| classes/local/net/object_url.php:83 | Concat (both) | Whether `$m[3] . $m[4]` holds `#` or `\` does not depend on the order of the two parts. |
| classes/local/net/object_url.php:94 | CastInt | `$parts[2]` matched `[0-9]{1,5}`. The port is compared with `=== $default` only together with `$host === $m[2]`, which is false whenever a port was written; the other comparisons (`>= 1`, `<= 65535`) are numeric for a numeric string, and the int parameter of the constructor turns the digits into the same number. |
| classes/local/net/object_url.php:121 | Concat | `strlen('.' . $suffix)` and `strlen($suffix . '.')` are the same number. |
| classes/local/net/transport.php:122 | CastString | As client.php:779. |
| classes/local/net/transport.php:186 | IncrementInteger | `Utils::copyToString()` reads until the length or the end of the stream: with MAX + 2 instead of MAX + 1, exactly the same bodies give more than MAX bytes. |
| classes/local/net/transport.php:201 | UnwrapTrim (both `trim()` calls) | The value is a header line of a Guzzle response. Guzzle trims spaces and tabs from header values and refuses every other character that `trim()` removes (CR, LF, NUL, vertical tab; checked), so `trim()` gives the value unchanged. |
| classes/local/net/transport.php:201 | CastInt | The value is 1 to 6 digits: `min()` compares it as a number, and the return type `?int` turns the digits into the same integer. |
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

## Escaped mutants that PHPUnit kills outside the tests that Infection runs for them

For each mutant, Infection runs only the tests that the coverage of its first run counts for the mutated line.

| File:line | Mutator | Test |
|---|---|---|
| classes/local/api/client.php:95 | Coalesce (`$clock ?? static fn(): int => time()` to `(static fn(): int => time()) ?? $clock`) | The mutant ignores the clock that a test gives, and client_runs_test fails on it (23 errors and 14 failures; applied by hand and run on 5.3 PostgreSQL). The line holds the arrow function, and the coverage counts the line for the tests in which that function runs, the clients made without a clock, which use the real time either way; it does not count the tests that give a clock. |

## Checks that the PHPUnit run of Infection does not make

Infection runs PHPUnit without the optimiser of OPcache: Moodle's PHPUnit bootstrap calls `opcache_reset()`
(`string_manager_standard::reset_caches()`), and on the command line the reset stays pending until the process ends, so
no class of the plugin is optimised there even with `opcache.enable_cli=1`. The optimiser removes dead stores. An
assignment that was meant to clear a secret parameter before an exception is made (`$sitesecretkey = ''` in the
constructor of `chunk_reader`) is one, so the secret stayed in the trace, and no PHPUnit run saw it. `unset()` is kept.
The trace tests of the chunk reader therefore run once more with OPcache compiling to a file cache, which a reset does not
stop: `.dev/ci/phpunit-opcache.sh` (CI: the step "PHPUnit trace tests with OPcache" of every row of the job `test`, so also
Moodle 4.5 with PHP 8.1, where `#[\SensitiveParameter]` does nothing; locally: `.dev/ci/run.sh`, or by hand
`.dev/ci/phpunit-opcache.sh /tmp/secbk-opcache .dev/env/run.sh <ver> <db>`). It fails unless OPcache did compile the reader.

Backup payloads (rows of the database, bytes of files) can be arguments in the trace of an exception of the writer, such
as `temp_write_failed`, when `zend.exception_ignore_args` is off. It is on in php.ini-production, and Moodle's handlers
print no arguments. They are data of the site, not secrets of the plugin. What the code must keep is narrower: no byte of
a payload goes into an exception message, a debuginfo string, a log line or an event.
