<?php
if (PHP_SAPI !== 'cli') {
    exit(1);
}
// Checks that every page and every operation of the plugin is in tests/behat/inventory.txt and has a Behat scenario.
// Development only; not part of the plugin package.
//
// Usage: php check-ui-inventory.php [plugin directory]     (default: the repository)
//
// The inventory has one line for each operation a person can do: "page | operation | feature file: scenario title".
// The page is the file of the plugin that serves it (index.php), settings.php for the entries of the administration
// tree, or "core:<path>" for a page of Moodle that this plugin adds to (its operations are Moodle's, not the plugin's).
// The operation is the English label of the button, link, menu entry or field. Lines starting with # and blank lines are
// ignored. The script fails, and prints the file and the missing name, when:
//  - a feature file does not carry @tool_secbackup (moodle-plugin-ci runs only the features with the tag of the component)
//    and @javascript (every scenario runs in a real browser) on its first line of tags;
//  - a web entry file at the root of the plugin is not a page of the inventory;
//  - a button label of a form (classes/form/), a link text of the renderer (classes/output/) or an entry of the
//    administration tree (settings.php) is not an operation of the inventory;
//  - a line of the inventory names a page that does not exist, or a scenario that does not exist;
//  - the scenario has no step that does the operation (press, click on, follow, set the field, set the following fields,
//    open, navigate to) with its label, or no step that checks what the page shows.

// Files at the root that Moodle includes or reads, and that nobody opens as a page.
const SECBK_NOT_PAGES = [
    'version.php' => 'read by Moodle to install and upgrade the plugin',
    'settings.php' => 'included by Moodle to build the administration tree; its entries are listed under settings.php',
    'lib.php' => 'included by Moodle for callbacks',
];
// Strings of Moodle that the plugin's forms use without a string of their own.
const SECBK_CORE_STRINGS = ['cancel' => 'Cancel'];
// The steps that do something with a label, and the steps that check what the page shows.
const SECBK_DOING = '/\b(I press|I click on|I follow|I set the field|I set the following fields|I open|I navigate to)\b/';
const SECBK_CHECKING = '/\b(should see|should not see|should exist|should not exist|should contain|should not contain)\b/';

/**
 * Lists the problems of a plugin directory.
 *
 * @param string $root The plugin directory.
 * @return string[] One text for each problem, starting with the file.
 */
function secbk_inventory_problems(string $root): array {
    $problems = [];
    $inventoryfile = $root . '/tests/behat/inventory.txt';
    if (!is_file($inventoryfile)) {
        return ['tests/behat/inventory.txt: the inventory does not exist'];
    }
    $features = secbk_read_features($root, $problems);
    $lines = secbk_read_inventory($inventoryfile, $problems);
    $strings = secbk_read_strings($root . '/lang/en/tool_secbackup.php');

    $pages = [];
    $operations = [];
    foreach ($lines as $line) {
        $pages[$line['page']] = true;
        $operations[$line['operation']] = true;
    }

    // Every web entry file is a page of the inventory.
    foreach (glob($root . '/*.php') ?: [] as $file) {
        $name = basename($file);
        if (!isset(SECBK_NOT_PAGES[$name]) && !isset($pages[$name])) {
            $problems[] = "$name: a web entry file that is not a page of tests/behat/inventory.txt";
        }
    }

    // Every button, link and entry of the administration tree is an operation of the inventory.
    foreach (secbk_code_labels($root, $strings, $problems) as $found) {
        if (!isset($operations[$found['label']])) {
            $problems[] = $found['file'] . ': ' . $found['kind'] . ' "' . $found['label'] . '" is not an operation of '
                . 'tests/behat/inventory.txt';
        }
    }

    // Every line names a page that exists and a scenario that does the operation.
    foreach ($lines as $line) {
        $where = 'tests/behat/inventory.txt:' . $line['number'];
        $iscore = strncmp($line['page'], 'core:', 5) === 0;
        if (!$iscore && !is_file($root . '/' . $line['page'])) {
            $problems[] = "$where: the page " . $line['page'] . ' does not exist';
        }
        [$file, $title] = array_pad(explode(': ', $line['scenario'], 2), 2, '');
        $scenario = $features[$file][$title] ?? null;
        if ($scenario === null) {
            $problems[] = "$where: the scenario \"" . $line['scenario'] . '" does not exist';
            continue;
        }
        if (!secbk_scenario_does($scenario, $line['operation'], $iscore)) {
            $problems[] = "$where: the scenario \"" . $line['scenario'] . '" has no step that does "'
                . $line['operation'] . '"';
        }
        if (!array_filter($scenario['steps'], fn(string $step): bool => preg_match(SECBK_CHECKING, $step) === 1)) {
            $problems[] = "$where: the scenario \"" . $line['scenario'] . '" has no step that checks what the page shows';
        }
    }
    return $problems;
}

/**
 * Reads the feature files: the scenarios with their steps, and the tags of each file.
 *
 * @param string $root The plugin directory.
 * @param string[] $problems Receives the problems.
 * @return array Scenarios by feature file name and title: ['steps' => lines, 'rows' => table rows after each step].
 */
function secbk_read_features(string $root, array &$problems): array {
    $features = [];
    foreach (glob($root . '/tests/behat/*.feature') ?: [] as $path) {
        $file = basename($path);
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $first = '';
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $first = trim($line);
                break;
            }
        }
        foreach (['@tool_secbackup', '@javascript'] as $tag) {
            if (!in_array($tag, preg_split('/\s+/', $first) ?: [], true)) {
                $problems[] = "tests/behat/$file: the first line of tags has no $tag";
            }
        }
        $features[$file] = [];
        $title = null;
        $laststep = null;
        foreach ($lines as $line) {
            $text = trim($line);
            if (preg_match('/^Scenario(?: Outline)?:\s*(.+)$/', $text, $match)) {
                $title = $match[1];
                $features[$file][$title] = ['steps' => [], 'rows' => []];
                $laststep = null;
            } else if (preg_match('/^(Background|Feature|Rule|Examples):/', $text)) {
                $title = null;
                $laststep = null;
            } else if ($title !== null && preg_match('/^(Given|When|Then|And|But)\s/', $text)) {
                $laststep = count($features[$file][$title]['steps']);
                $features[$file][$title]['steps'][] = $text;
                $features[$file][$title]['rows'][$laststep] = [];
            } else if ($title !== null && $laststep !== null && $text !== '' && $text[0] === '|') {
                $cells = array_map('trim', explode('|', trim($text, '|')));
                $features[$file][$title]['rows'][$laststep][] = $cells[0];
            }
        }
    }
    return $features;
}

/**
 * Reads the inventory.
 *
 * @param string $file The inventory file.
 * @param string[] $problems Receives the problems.
 * @return array[] Lines: number, page, operation, scenario.
 */
function secbk_read_inventory(string $file, array &$problems): array {
    $lines = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $index => $text) {
        $text = trim($text);
        if ($text === '' || $text[0] === '#') {
            continue;
        }
        $fields = array_map('trim', explode(' | ', $text));
        if (count($fields) !== 3 || in_array('', $fields, true)) {
            $problems[] = 'tests/behat/inventory.txt:' . ($index + 1) . ': a line needs "page | operation | feature file: '
                . 'scenario title"';
            continue;
        }
        $lines[] = ['number' => $index + 1, 'page' => $fields[0], 'operation' => $fields[1], 'scenario' => $fields[2]];
    }
    return $lines;
}

/**
 * Tells whether a scenario does an operation with its label.
 *
 * @param array $scenario The scenario: steps and table rows.
 * @param string $label The label of the operation.
 * @param bool $seeing Whether a step that only looks at the label counts (the pages of Moodle).
 * @return bool
 */
function secbk_scenario_does(array $scenario, string $label, bool $seeing): bool {
    foreach ($scenario['steps'] as $index => $step) {
        $does = preg_match(SECBK_DOING, $step) === 1 || ($seeing && preg_match(SECBK_CHECKING, $step) === 1);
        if (!$does) {
            continue;
        }
        if (preg_match_all('/"([^"]*)"/', $step, $quoted)) {
            foreach ($quoted[1] as $text) {
                foreach (explode('>', $text) as $part) {
                    if (trim($part) === $label) {
                        return true;
                    }
                }
            }
        }
        if (in_array($label, $scenario['rows'][$index], true)) {
            return true;
        }
    }
    return false;
}

/**
 * Reads the English strings of the plugin.
 *
 * @param string $file The language file.
 * @return string[] Text by string id.
 */
function secbk_read_strings(string $file): array {
    $strings = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\$string\[\'([a-z0-9_]+)\'\]\s*=\s*\'(.*)\';\s*$/', $line, $match)) {
            $strings[$match[1]] = stripcslashes($match[2]);
        }
    }
    return $strings;
}

/**
 * Finds the button labels, link texts and tree entries in the code.
 *
 * @param string $root The plugin directory.
 * @param string[] $strings English strings of the plugin.
 * @param string[] $problems Receives the problems.
 * @return array[] Each with file, kind and label.
 */
function secbk_code_labels(string $root, array $strings, array &$problems): array {
    $found = [];
    $sources = [
        // Call => [kind, index of the argument that holds the label].
        'classes/form' => ['add_action_buttons' => ['button', 1]],
        'classes/output' => ['html_writer::link' => ['link', 1], 'action_link' => ['link', 1]],
    ];
    $files = [];
    foreach ($sources as $dir => $calls) {
        foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $path) {
            $files[] = [$path, $dir . '/' . basename($path), $calls];
        }
    }
    foreach ($files as [$path, $name, $calls]) {
        $code = secbk_without_comments((string) file_get_contents($path));
        foreach ($calls as $call => [$kind, $index]) {
            foreach (secbk_calls($code, $call) as $arguments) {
                if ($call === 'add_action_buttons' && trim($arguments[0] ?? '') === 'true') {
                    $found[] = ['file' => $name, 'kind' => 'button', 'label' => SECBK_CORE_STRINGS['cancel']];
                }
                $label = secbk_label_of($arguments[$index] ?? '', $strings);
                if ($label === null) {
                    $problems[] = "$name: the label of a $kind cannot be read from " . trim($arguments[$index] ?? '(nothing)');
                    continue;
                }
                $found[] = ['file' => $name, 'kind' => $kind, 'label' => $label];
            }
        }
        foreach (secbk_calls($code, '$mform->addElement') as $arguments) {
            $type = trim($arguments[0] ?? '', " '\"");
            if (in_array($type, ['submit', 'cancel', 'button'], true)) {
                $label = secbk_label_of($arguments[2] ?? '', $strings);
                if ($label === null) {
                    $problems[] = "$name: the label of a $type element cannot be read from " . trim($arguments[2] ?? '(nothing)');
                    continue;
                }
                $found[] = ['file' => $name, 'kind' => 'button', 'label' => $label];
            }
        }
    }
    // The entries of the administration tree.
    $settings = $root . '/settings.php';
    if (is_file($settings)) {
        $code = secbk_without_comments((string) file_get_contents($settings));
        foreach (secbk_calls($code, 'new lang_string') as $arguments) {
            $label = secbk_label_of('get_string(' . implode(',', $arguments) . ')', $strings);
            if ($label === null) {
                $problems[] = 'settings.php: the name of an entry cannot be read from lang_string(' . implode(',', $arguments) . ')';
                continue;
            }
            $found[] = ['file' => 'settings.php', 'kind' => 'entry of the administration tree', 'label' => $label];
        }
    }
    return $found;
}

/**
 * Gives the English text of an expression that is a string of the plugin, a string of Moodle that is known, or a literal.
 *
 * @param string $expression PHP expression.
 * @param string[] $strings English strings of the plugin.
 * @return string|null The text, or null when the expression is something else.
 */
function secbk_label_of(string $expression, array $strings): ?string {
    $expression = trim($expression);
    if (preg_match('/^get_string\(\s*\'([a-z0-9_]+)\'\s*,\s*\'tool_secbackup\'/', $expression, $match)) {
        return $strings[$match[1]] ?? null;
    }
    if (preg_match('/^get_string\(\s*\'([a-z0-9_]+)\'\s*\)$/', $expression, $match)) {
        return SECBK_CORE_STRINGS[$match[1]] ?? null;
    }
    if (preg_match('/^\'([^\'\\\\]*)\'$/', $expression, $match)) {
        return $match[1];
    }
    return null;
}

/**
 * Removes the comments of PHP code.
 *
 * @param string $code PHP code.
 * @return string
 */
function secbk_without_comments(string $code): string {
    $out = '';
    foreach (token_get_all($code) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

/**
 * Finds the calls of a function or method and splits their arguments.
 *
 * @param string $code PHP code without comments.
 * @param string $name The name as it is written before the parenthesis.
 * @return array[] The arguments of each call, as text.
 */
function secbk_calls(string $code, string $name): array {
    $calls = [];
    $offset = 0;
    while (($at = strpos($code, $name . '(', $offset)) !== false) {
        $offset = $at + strlen($name) + 1;
        if ($at > 0 && preg_match('/[A-Za-z0-9_]/', $code[$at - 1])) {
            // Part of a longer name (for example "my_add_action_buttons").
            continue;
        }
        $arguments = [];
        $current = '';
        $depth = 0;
        $quote = '';
        $length = strlen($code);
        for ($i = $offset; $i < $length; $i++) {
            $char = $code[$i];
            if ($quote !== '') {
                $current .= $char;
                if ($char === '\\') {
                    $current .= $code[++$i] ?? '';
                } else if ($char === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
            } else if ($char === '(' || $char === '[') {
                $depth++;
            } else if ($char === ')' || $char === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } else if ($char === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $arguments[] = trim($current);
        }
        $calls[] = $arguments;
        $offset = $i;
    }
    return $calls;
}

$root = realpath($argv[1] ?? dirname(__DIR__, 2));
if ($root === false || !is_dir($root)) {
    fwrite(STDERR, "not a directory: " . ($argv[1] ?? '') . "\n");
    exit(1);
}
$problems = secbk_inventory_problems($root);
foreach ($problems as $problem) {
    fwrite(STDERR, $problem . "\n");
}
if ($problems) {
    exit(1);
}
echo "ui inventory ok\n";
