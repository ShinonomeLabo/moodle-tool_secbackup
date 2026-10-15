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
// The operation is the English label of the button, link or menu entry, or "field: <label>" for a field that is typed into
// (the typed confirmation of a removal). Lines starting with # and blank lines are ignored.
// A label belongs to a page: the script follows the entry file to its page class, to the forms the class makes and to the
// methods of the renderer that it calls, so a label of one page does not count for another. The script fails, and prints
// the file and the missing name, when:
//  - a feature file does not carry @tool_secbackup (moodle-plugin-ci runs only the features with the tag of the component)
//    and @javascript (every scenario runs in a real browser) on its first line of tags;
//  - a web entry file at the root of the plugin is not a page of the inventory;
//  - a button label of a form (classes/form/, in every folder below: add_action_buttons, and submit, cancel and button
//    elements made with addElement or createElement, also inside a group), a link or button text of the renderer
//    (classes/output/: html_writer::link, action_link, single_button) or an entry of the administration tree
//    (settings.php) is not an operation of the inventory for the page that shows it;
//  - a label of the code cannot be read, or is not reached from any page;
//  - a line of the inventory is not found in the code of its page (so a removed button leaves no stale line), names a page
//    that does not exist, or names a scenario that does not exist;
//  - the scenario has no step that does the operation with its label (press, click on, follow, set the field, set the
//    following fields, open, navigate to: of a path "tab > row > link" the tab, the row and the link count, as the step
//    uses them), or no step after it that checks what the page shows.

// Files at the root that Moodle includes or reads, and that nobody opens as a page.
const SECBK_NOT_PAGES = [
    'version.php' => 'read by Moodle to install and upgrade the plugin',
    'settings.php' => 'included by Moodle to build the administration tree; its entries are listed under settings.php',
    'lib.php' => 'included by Moodle for callbacks',
];
// Strings of Moodle that the plugin's forms use without a string of their own.
const SECBK_CORE_STRINGS = ['cancel' => 'Cancel', 'savechanges' => 'Save changes'];
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
    $found = secbk_code_labels($root, $strings, $problems);

    $pages = [];
    $rows = [];
    foreach ($lines as $line) {
        $pages[$line['page']] = true;
        $rows[$line['page'] . "\n" . $line['label']] = true;
    }

    // Every web entry file is a page of the inventory.
    foreach (glob($root . '/*.php') ?: [] as $file) {
        $name = basename($file);
        if (!isset(SECBK_NOT_PAGES[$name]) && !isset($pages[$name])) {
            $problems[] = "$name: a web entry file that is not a page of tests/behat/inventory.txt";
        }
    }

    // Every button, link and entry of the administration tree is an operation of the inventory for its page.
    $inthecode = [];
    foreach ($found as $item) {
        if ($item['page'] === null) {
            $problems[] = $item['file'] . ': ' . $item['kind'] . ' "' . $item['label'] . '" is not reached from any page';
            continue;
        }
        $inthecode[$item['page'] . "\n" . $item['label']] = true;
        if ($item['kind'] !== 'field' && !isset($rows[$item['page'] . "\n" . $item['label']])) {
            $problems[] = $item['file'] . ': ' . $item['kind'] . ' "' . $item['label'] . '" of ' . $item['page']
                . ' is not an operation of tests/behat/inventory.txt';
        }
    }

    // Every line names a page that exists, an operation that the code of the page still has, and a scenario that does it.
    foreach ($lines as $line) {
        $where = 'tests/behat/inventory.txt:' . $line['number'];
        $iscore = strncmp($line['page'], 'core:', 5) === 0;
        if (!$iscore && !is_file($root . '/' . $line['page'])) {
            $problems[] = "$where: the page " . $line['page'] . ' does not exist';
        } else if (!$iscore && !isset($inthecode[$line['page'] . "\n" . $line['label']])) {
            $problems[] = "$where: the code of " . $line['page'] . ' has no operation "' . $line['label'] . '"';
        }
        [$file, $title] = array_pad(explode(': ', $line['scenario'], 2), 2, '');
        $scenario = $features[$file][$title] ?? null;
        if ($scenario === null) {
            $problems[] = "$where: the scenario \"" . $line['scenario'] . '" does not exist';
            continue;
        }
        $at = secbk_scenario_does($scenario, $line['label'], $iscore);
        if ($at === null) {
            $problems[] = "$where: the scenario \"" . $line['scenario'] . '" has no step that does "' . $line['label'] . '"';
        } else if (!$iscore && !secbk_checks_after($scenario, $at)) {
            $problems[] = "$where: the scenario \"" . $line['scenario'] . '" has no step after "' . $line['label']
                . '" that checks what the page shows';
        }
    }
    return $problems;
}

/**
 * Reads the feature files: the scenarios with their steps, and the tags of each file.
 *
 * @param string $root The plugin directory.
 * @param string[] $problems Receives the problems.
 * @return array Scenarios by feature file name and title: ['steps' => lines, 'rows' => first cells of the table of each step].
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
 * @return array[] Lines: number, page, operation, label (the operation without "field: "), field, scenario.
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
        $isfield = strncmp($fields[1], 'field: ', 7) === 0;
        $lines[] = [
            'number' => $index + 1,
            'page' => $fields[0],
            'operation' => $fields[1],
            'label' => $isfield ? substr($fields[1], 7) : $fields[1],
            'field' => $isfield,
            'scenario' => $fields[2],
        ];
    }
    return $lines;
}

/**
 * Gives the names of a step of the steps that open a page of the administration, as the step uses them.
 *
 * The step takes the tab (first), the heading of a row (second, only when the path has a third name) and the link (last).
 *
 * @param string $path Names separated by ">".
 * @return string[]
 */
function secbk_path_parts_used(string $path): array {
    $parts = array_map('trim', explode('>', $path));
    if (count($parts) < 2) {
        return $parts;
    }
    $used = [$parts[0], $parts[count($parts) - 1]];
    if (count($parts) >= 3) {
        $used[] = $parts[1];
    }
    return $used;
}

/**
 * Finds the step of a scenario that does an operation with its label.
 *
 * Only the first text in quotes of a step is the label (the others are values, selectors and containers). Of a path to
 * open, the names that the step uses count.
 *
 * @param array $scenario The scenario: steps and table rows.
 * @param string $label The label of the operation.
 * @param bool $seeing Whether a step that only looks at the label counts (the pages of Moodle).
 * @return int|null The index of the step, or null.
 */
function secbk_scenario_does(array $scenario, string $label, bool $seeing): ?int {
    foreach ($scenario['steps'] as $index => $step) {
        $navigates = preg_match('/\b(I open|I navigate to)\b/', $step) === 1;
        $does = preg_match(SECBK_DOING, $step) === 1 || ($seeing && preg_match(SECBK_CHECKING, $step) === 1);
        if (!$does) {
            continue;
        }
        if (preg_match('/"([^"]*)"/', $step, $quoted)) {
            $names = $navigates ? secbk_path_parts_used($quoted[1]) : [$quoted[1]];
            if (in_array($label, $names, true)) {
                return $index;
            }
        }
        if (in_array($label, $scenario['rows'][$index], true)) {
            return $index;
        }
    }
    return null;
}

/**
 * Tells whether a scenario has a step that checks what the page shows after the given step.
 *
 * @param array $scenario The scenario.
 * @param int $after Index of the step that does the operation.
 * @return bool
 */
function secbk_checks_after(array $scenario, int $after): bool {
    foreach ($scenario['steps'] as $index => $step) {
        if ($index > $after && preg_match(SECBK_CHECKING, $step) === 1) {
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
 * Lists the PHP files below a folder, in every depth.
 *
 * @param string $dir The folder.
 * @return string[] Paths.
 */
function secbk_php_files(string $dir): array {
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);
    return $files;
}

/**
 * Splits the arguments of a call into positional ones and named ones.
 *
 * @param string[] $arguments The arguments as text.
 * @param string[] $names The names of the parameters, in order.
 * @return string[] The argument text by parameter name.
 */
function secbk_named(array $arguments, array $names): array {
    $result = [];
    foreach ($arguments as $position => $argument) {
        if (preg_match('/^([a-zA-Z_]\w*)\s*:(?!:)\s*(.*)$/s', $argument, $match)) {
            $result[$match[1]] = $match[2];
        } else if (isset($names[$position])) {
            $result[$names[$position]] = $argument;
        }
    }
    return $result;
}

/**
 * Finds the buttons and fields of a form file.
 *
 * @param string $name File name for the messages.
 * @param string $code PHP code without comments.
 * @param string[] $strings English strings of the plugin.
 * @param string[] $problems Receives the problems.
 * @return array[] Each with file, kind and label.
 */
function secbk_form_labels(string $name, string $code, array $strings, array &$problems): array {
    $found = [];
    foreach (secbk_calls($code, 'add_action_buttons') as $arguments) {
        $named = secbk_named($arguments, ['cancel', 'submitlabel']);
        // Moodle: a cancel button unless the first argument is false; no label means "Save changes".
        if (strtolower(trim($named['cancel'] ?? 'true')) !== 'false') {
            $found[] = ['file' => $name, 'kind' => 'button', 'label' => SECBK_CORE_STRINGS['cancel']];
        }
        $expression = $named['submitlabel'] ?? 'null';
        $label = strtolower(trim($expression)) === 'null' ? SECBK_CORE_STRINGS['savechanges']
            : secbk_label_of($expression, $strings);
        if ($label === null) {
            $problems[] = "$name: the label of a button cannot be read from " . trim($expression);
            continue;
        }
        $found[] = ['file' => $name, 'kind' => 'button', 'label' => $label];
    }
    foreach (['addElement', 'createElement'] as $method) {
        foreach (secbk_calls($code, '->' . $method) as $arguments) {
            $type = trim($arguments[0] ?? '', " '\"");
            $isbutton = in_array($type, ['submit', 'cancel', 'button'], true);
            if ($type === 'cancel' && !isset($arguments[2])) {
                // addElement('cancel') has the label of Moodle's cancel button.
                $found[] = ['file' => $name, 'kind' => 'button', 'label' => SECBK_CORE_STRINGS['cancel']];
                continue;
            }
            $label = secbk_label_of($arguments[2] ?? '', $strings);
            if ($label === null) {
                // A field whose label cannot be read is not an operation that the inventory must have; a button is.
                if ($isbutton) {
                    $problems[] = "$name: the label of a $type element cannot be read from " . trim($arguments[2] ?? '(nothing)');
                }
                continue;
            }
            if ($isbutton) {
                $found[] = ['file' => $name, 'kind' => 'button', 'label' => $label];
            } else if (!in_array($type, ['hidden', 'static', 'header', 'html', 'group'], true)) {
                $found[] = ['file' => $name, 'kind' => 'field', 'label' => $label];
            }
        }
    }
    return $found;
}

/**
 * Finds the links and buttons in the methods of the renderer.
 *
 * @param string $name File name for the messages.
 * @param string $code PHP code without comments.
 * @param string[] $strings English strings of the plugin.
 * @param string[] $problems Receives the problems.
 * @return array[] Methods by name: calls (the other methods of the renderer that it calls) and items (file, kind, label).
 */
function secbk_renderer_methods(string $name, string $code, array $strings, array &$problems): array {
    $methods = [];
    preg_match_all('/function\s+(\w+)\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE);
    foreach ($matches[1] as $index => [$method, $offset]) {
        $end = isset($matches[0][$index + 1]) ? $matches[0][$index + 1][1] : strlen($code);
        $body = substr($code, $offset, $end - $offset);
        $items = [];
        $calls = ['html_writer::link' => 'link', 'action_link' => 'link', 'single_button' => 'button'];
        foreach ($calls as $call => $kind) {
            foreach (secbk_calls($body, $call) as $arguments) {
                $label = secbk_label_of($arguments[1] ?? '', $strings);
                if ($label === null) {
                    $problems[] = "$name: the label of a $kind cannot be read from " . trim($arguments[1] ?? '(nothing)');
                    continue;
                }
                $items[] = ['file' => $name, 'kind' => $kind, 'label' => $label];
            }
        }
        preg_match_all('/\$this->(\w+)\(/', $body, $called);
        $methods[$method] = ['calls' => array_unique($called[1]), 'items' => $items];
    }
    return $methods;
}

/**
 * Finds the button labels, link texts, entries of the administration tree and fields of the plugin, and the page of each.
 *
 * A page is a file at the root. Its page class (the class of "new ...\page\x_page" in the file) makes forms and calls
 * methods of the renderer, and a method of the renderer calls others.
 *
 * @param string $root The plugin directory.
 * @param string[] $strings English strings of the plugin.
 * @param string[] $problems Receives the problems.
 * @return array[] Each with file, kind, label and page (null when no page reaches it).
 */
function secbk_code_labels(string $root, array $strings, array &$problems): array {
    $forms = [];
    foreach (secbk_php_files($root . '/classes/form') as $path) {
        $forms[basename($path, '.php')] = secbk_form_labels(
            substr($path, strlen($root) + 1),
            secbk_without_comments((string) file_get_contents($path)),
            $strings,
            $problems
        );
    }
    $methods = [];
    foreach (secbk_php_files($root . '/classes/output') as $path) {
        $methods += secbk_renderer_methods(
            substr($path, strlen($root) + 1),
            secbk_without_comments((string) file_get_contents($path)),
            $strings,
            $problems
        );
    }
    $classfiles = [];
    foreach (secbk_php_files($root . '/classes') as $path) {
        $classfiles[basename($path, '.php')] = $path;
    }

    $found = [];
    $reached = [];
    foreach (glob($root . '/*.php') ?: [] as $entry) {
        $page = basename($entry);
        if (isset(SECBK_NOT_PAGES[$page])) {
            continue;
        }
        $code = secbk_without_comments((string) file_get_contents($entry));
        $pagecode = '';
        if (preg_match_all('/\\\\page\\\\(\w+)\s*\(/', $code, $classes)) {
            foreach ($classes[1] as $class) {
                if (isset($classfiles[$class])) {
                    $pagecode .= secbk_without_comments((string) file_get_contents($classfiles[$class]));
                }
            }
        }
        $pagecode .= $code;
        foreach ($forms as $form => $items) {
            if (preg_match('/new\s+\\\\?(?:[\w\\\\]*\\\\)?' . preg_quote($form, '/') . '\s*\(/', $pagecode)) {
                $reached[$form . "\n" . $page] = true;
                foreach ($items as $item) {
                    $found[] = $item + ['page' => $page];
                }
            }
        }
        $todo = [];
        if (preg_match_all('/->(\w+)\(/', $pagecode, $called)) {
            $todo = array_values(array_unique($called[1]));
        }
        $seen = [];
        while ($todo) {
            $method = array_pop($todo);
            if (isset($seen[$method]) || !isset($methods[$method])) {
                continue;
            }
            $seen[$method] = true;
            $reached['method ' . $method] = true;
            foreach ($methods[$method]['items'] as $item) {
                $found[] = $item + ['page' => $page];
            }
            $todo = array_merge($todo, $methods[$method]['calls']);
        }
    }
    // What no page reaches.
    foreach ($forms as $form => $items) {
        $isreached = false;
        foreach ($reached as $key => $unused) {
            $isreached = $isreached || strncmp($key, $form . "\n", strlen($form) + 1) === 0;
        }
        foreach ($isreached ? [] : $items as $item) {
            $found[] = $item + ['page' => null];
        }
    }
    foreach ($methods as $method => $data) {
        foreach (isset($reached['method ' . $method]) ? [] : $data['items'] as $item) {
            $found[] = $item + ['page' => null];
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
            $found[] = ['file' => 'settings.php', 'kind' => 'entry of the administration tree', 'label' => $label,
                'page' => 'settings.php'];
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
        if ($at > 0 && preg_match('/\w/', $name[0]) && preg_match('/[\w$]/', $code[$at - 1])) {
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
