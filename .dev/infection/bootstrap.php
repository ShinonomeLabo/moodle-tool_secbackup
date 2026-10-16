<?php
// Development only; not part of the plugin package.
//
// Infection reads the classes of the source folders with reflection in its own process, which does not load Moodle. The
// classes of this plugin extend those of Moodle (moodle_exception, ...), and Moodle finds all of its classes by its own
// autoloader, so this process loads the development site's config.php as a command line script. The development site is
// only read; the tests run in the PHPUnit site, in processes of their own.

define('CLI_SCRIPT', true);
$cwd = getcwd();
$root = __DIR__;
while (!is_file($root . '/config.php')) {
    $parent = dirname($root);
    if ($parent === $root) {
        throw new RuntimeException('config.php of Moodle not found above ' . __DIR__);
    }
    $root = $parent;
}
require($root . '/config.php');
// Moodle changes the working directory; the paths of the Infection configuration are relative to the plugin.
chdir($cwd);
