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

namespace tool_secbackup\tests;

/**
 * Finds what a PHP source uses from outside PHP itself.
 *
 * The development server loads the fake service and its response signer without Moodle, and a test that checks the plugin
 * with the plugin's own classes would pass even when both are wrong in the same way. So these files may name only PHP's
 * own functions, constants and classes, their own two classes, and the libraries the test doubles for the transport need
 * (Guzzle and the PSR interfaces). A global variable of Moodle is refused too. The scan reads the tokens of the source, so
 * a name written in any form (fully qualified, imported, in a type, after new, instanceof or catch) is found.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class standalone_scan {
    /** @var string[] The classes that stand on their own: the two files this scan guards. */
    private const OWN = ['tool_secbackup\tests\fake_service', 'tool_secbackup\tests\envelope_builder'];

    /** @var string[] Namespaces of libraries that the test doubles for the transport need. */
    private const LIBRARIES = ['GuzzleHttp\\', 'Psr\\'];

    /** @var string[] Names that are types or values of the language itself. */
    private const LANGUAGE = ['self', 'parent', 'static', 'true', 'false', 'null', 'int', 'float', 'string', 'bool', 'array',
        'callable', 'iterable', 'object', 'mixed', 'void', 'never'];

    /**
     * What a source uses that is not PHP's own.
     *
     * @param string $source PHP source.
     * @param string $label Name of the file, for the messages.
     * @return string[] One entry for each use: the file, the line and the name.
     */
    public static function problems(string $source, string $label): array {
        $tokens = self::significant(token_get_all($source));
        $problems = [];
        $namespace = '';
        $imports = [];
        $depth = 0;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $tokens[$i];
            if ($id === T_NAMESPACE) {
                [$namespace, $i] = self::name_until($tokens, $i + 1);
                continue;
            }
            if ($id === T_USE && $depth === 0) {
                $i = self::read_import($tokens, $i, $imports);
                continue;
            }
            if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) || $text === '{') {
                $depth++;
                continue;
            }
            if ($text === '}') {
                $depth--;
                continue;
            }
            if ($id === T_GLOBAL || ($id === T_VARIABLE && $text === '$GLOBALS')) {
                $problems[] = "$label:$line $text";
                continue;
            }
            if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $previous = $tokens[$i - 1][0] ?? null;
            $next = $tokens[$i + 1][1] ?? '';
            if (self::is_declaration_or_member($previous, $tokens[$i - 1][1] ?? '', $next)) {
                continue;
            }
            $problem = self::judge($text, $previous, $next, $namespace, $imports);
            if ($problem !== null) {
                $problems[] = "$label:$line $text";
            }
        }
        return $problems;
    }

    /**
     * The tokens that matter: without white space and comments, each as [id, text, line].
     *
     * @param array $raw Result of token_get_all().
     * @return array
     */
    private static function significant(array $raw): array {
        $tokens = [];
        $line = 1;
        foreach ($raw as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (!in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                    $tokens[] = [$token[0], $token[1], $token[2]];
                }
                $line += substr_count($token[1], "\n");
            } else {
                $tokens[] = [null, $token, $line];
            }
        }
        return $tokens;
    }

    /**
     * Reads a name up to the next ";" or "{".
     *
     * @param array $tokens The tokens.
     * @param int $from Position to start at.
     * @return array The name, and the position of the token that ended it.
     */
    private static function name_until(array $tokens, int $from): array {
        $name = '';
        $i = $from;
        while (isset($tokens[$i]) && !in_array($tokens[$i][1], [';', '{'], true)) {
            $name .= $tokens[$i][1] . ' ';
            $i++;
        }
        return [trim($name), $i];
    }

    /**
     * Reads an import (use A\B; use A\B as C;) into the map of imports.
     *
     * @param array $tokens The tokens.
     * @param int $at Position of "use".
     * @param array $imports Map of the imports by their short name, changed here.
     * @return int Position of the token that ended the import.
     */
    private static function read_import(array $tokens, int $at, array &$imports): int {
        [$text, $end] = self::name_until($tokens, $at + 1);
        foreach (explode(',', $text) as $part) {
            $pieces = preg_split('/\s+as\s+/i', trim($part));
            $full = ltrim(trim($pieces[0]), '\\');
            $short = isset($pieces[1]) ? trim($pieces[1]) : substr(strrchr('\\' . $full, '\\'), 1);
            $imports[strtolower($short)] = $full;
        }
        return $end;
    }

    /**
     * Whether a name is the name of something this source declares, or of a member that is read after "->" or "::".
     *
     * @param int|null $previous Id of the token before.
     * @param string $previoustext Text of the token before.
     * @param string $next Text of the token after.
     * @return bool
     */
    private static function is_declaration_or_member(?int $previous, string $previoustext, string $next): bool {
        $declares = [T_FUNCTION, T_CONST, T_CLASS, T_INTERFACE, T_TRAIT, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON];
        if (in_array($previous, $declares, true)) {
            return true;
        }
        // A named argument: name: value.
        return $next === ':' && in_array($previoustext, ['(', ','], true);
    }

    /**
     * Judges a name.
     *
     * @param string $name The name as written.
     * @param int|null $previous Id of the token before.
     * @param string $next Text of the token after.
     * @param string $namespace Namespace of the source.
     * @param array $imports Imports of the source.
     * @return string|null Null when the name is allowed.
     */
    private static function judge(string $name, ?int $previous, string $next, string $namespace, array $imports): ?string {
        if (in_array(strtolower($name), self::LANGUAGE, true)) {
            return null;
        }
        $candidates = self::candidates($name, $namespace, $imports, $next === '(' && $previous !== T_NEW);
        if ($next === '(' && $previous !== T_NEW) {
            foreach ($candidates as $candidate) {
                if (function_exists($candidate)) {
                    return (new \ReflectionFunction($candidate))->isInternal() ? null : 'function';
                }
            }
            return 'function';
        }
        if ($next !== '::' && !in_array($previous, [T_NEW, T_INSTANCEOF], true)) {
            foreach ($candidates as $candidate) {
                if (defined(ltrim($candidate, '\\'))) {
                    $user = get_defined_constants(true)['user'] ?? [];
                    return isset($user[ltrim($candidate, '\\')]) ? 'constant' : null;
                }
            }
        }
        foreach ($candidates as $candidate) {
            if (class_exists($candidate) || interface_exists($candidate) || trait_exists($candidate) || enum_exists($candidate)) {
                return self::class_is_allowed($candidate) ? null : 'class';
            }
        }
        return 'class';
    }

    /**
     * Whether a class that exists may be used.
     *
     * @param string $class Full name.
     * @return bool
     */
    private static function class_is_allowed(string $class): bool {
        $class = ltrim($class, '\\');
        if (in_array($class, self::OWN, true) || (new \ReflectionClass($class))->isInternal()) {
            return true;
        }
        foreach (self::LIBRARIES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The full names that a name may stand for, the one PHP tries first at the front.
     *
     * @param string $name The name as written.
     * @param string $namespace Namespace of the source.
     * @param array $imports Imports of the source.
     * @param bool $function Whether it is called as a function.
     * @return string[]
     */
    private static function candidates(string $name, string $namespace, array $imports, bool $function): array {
        if ($name[0] === '\\') {
            return [ltrim($name, '\\')];
        }
        $first = strtolower(strstr($name, '\\', true) ?: $name);
        if (!$function && isset($imports[$first])) {
            return [$imports[$first] . (str_contains($name, '\\') ? substr($name, strlen($first)) : '')];
        }
        $inside = ($namespace === '' ? '' : $namespace . '\\') . $name;
        // An unqualified name is also tried as a global one: functions and constants fall back to it.
        return str_contains($name, '\\') ? [$inside] : [$inside, $name];
    }
}
