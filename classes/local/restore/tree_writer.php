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

namespace tool_secbackup\local\restore;

use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Writes the files of a restore under one folder and nowhere else, whatever the paths of the list of the generation say.
 *
 * The paths come from the storage, so a path that is not a plain relative path is refused (check_path()). A symbolic link on
 * the way is not followed either, whether it was in the folder before or is put there while the writer works: the restore runs
 * as root, and a link could lead it anywhere.
 *
 * PHP has no openat() and no O_NOFOLLOW, and its fopen() follows a link even in the mode "x" (PHP resolves the path itself
 * before it opens it). So the writer goes down the folders one name at a time: it looks at the name with lstat(), enters it
 * with chdir(), and checks that the folder it is in is the one it looked at (the same device and inode). A link, or a folder
 * swapped for a link in between, leads to another folder and is refused there, before anything is written. On a PHP without
 * thread safety (NTS, as the CLI of the restore host is), each later step (lstat(), mkdir(), unlink(), rename(), chmod('.'))
 * names one entry of the folder the process is in, which no change of a parent folder can redirect. A PHP with thread safety
 * (ZTS) keeps a working folder of its own and makes those calls with absolute paths: a link that was there before is still
 * refused by the same comparison, but a parent folder swapped while the writer works is not.
 *
 * Only fopen() goes by an absolute path again: PHP joins getcwd() and the name, resolves the links in it and then opens it.
 * Two changes are not prevented: a parent folder swapped for a link between that getcwd() and the open(2), and a link put as
 * the part file between the unlink() of the one that was there and the fopen(). Either makes PHP create an empty file where
 * the link leads. The writer then finds that the file it opened is not that entry of the folder and refuses it before it
 * gives the handle, so no byte of content is written there; it checks again before the rename. The restore relies on no other
 * process writing in the folders while it runs.
 *
 * A file is written as "<name>.part" and takes its name in commit(). It is made with the mode of the writer at once (through
 * the umask), so no chmod() ever names a file. A folder that the writer makes gets its mode from inside, with chmod('.'),
 * because mkdir() drops the set-group-ID bit. Folders that are there already keep their mode.
 *
 * "<name>.part" is also a name a site can have. A part file of an earlier restore that stopped is removed by open(), but a file
 * that this writer has given that name is not: open() of "<name>" then fails, and commit() of a file whose part file was taken
 * by another one fails too. abort() removes only the part file that the writer made.
 *
 * Each call changes the working folder and the umask of the process and puts both back before it returns.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tree_writer {
    /** @var string What is added to the name of a file while it is written. */
    private const PART = '.part';

    /** @var int A folder that is missing on the way is made. */
    private const MAKE = 1;

    /** @var int A folder that is missing on the way is an error. */
    private const NEED = 2;

    /** @var int A folder that is missing on the way ends the call: nothing is in it. */
    private const MAYBE = 3;

    /** @var int The bits of the type in the mode of lstat(). */
    private const TYPE = 0170000;

    /** @var int The type of a symbolic link. */
    private const LINK = 0120000;

    /** @var int The type of a folder. */
    private const FOLDER = 0040000;

    /** @var string The folder that the paths are under. */
    private string $base;

    /** @var int Mode of the files. */
    private int $filemode;

    /** @var int Mode of the folders that the writer makes. */
    private int $dirmode;

    /** @var array[] The files that are open: ['handle', 'dev', 'ino'] of the part file, by path. */
    private array $open = [];

    /** @var true[] The paths ending with ".part" that this writer has committed, as keys. */
    private array $committedparts = [];

    /**
     * Constructor.
     *
     * @param string $base The folder that the paths are under, as an absolute path. It is trusted: it may itself be a link.
     * @param int $filemode Mode of the files: read and write bits only (fopen() makes files with 0666 at most).
     * @param int $dirmode Mode of the folders that the writer makes, with the special bits if wanted.
     * @throws \coding_exception When the folder is not absolute or a mode is out of range.
     */
    public function __construct(string $base, int $filemode, int $dirmode) {
        $absolute = str_starts_with($base, '/') && !str_contains($base, "\0");
        if (!$absolute || ($filemode & ~0666) !== 0 || ($dirmode & ~07777) !== 0) {
            throw new \coding_exception('tree_writer needs an absolute folder, file mode within 0666, folder mode within 07777');
        }
        $this->base = $base;
        $this->filemode = $filemode;
        $this->dirmode = $dirmode;
    }

    /**
     * Refuses a path that is not a plain relative path.
     *
     * Refused: the empty path, an absolute path, a part that is empty (a//b, a trailing slash), "." or "..", a backslash, and a
     * control character (NUL, C0, DEL and C1) or text that is not UTF-8.
     *
     * @param string $path The path.
     * @throws plugin_exception With restore_path_unsafe.
     */
    public static function check_path(string $path): void {
        $plain = preg_match('/\A[^\p{Cc}\\\\]+\z/u', $path) === 1;
        if (!$plain || array_intersect(explode('/', $path), ['', '.', '..']) !== []) {
            throw new plugin_exception('restore_path_unsafe');
        }
    }

    /**
     * Makes the folders on the way and opens the part file of a file to write it.
     *
     * When the part file that was opened turns out not to be the entry of the folder, the path stays open: abort() closes it.
     *
     * @param string $path The path of the file under the folder of the writer.
     * @return resource The handle to write the content with. commit() or abort() closes it.
     * @throws plugin_exception With restore_path_unsafe when the path is not plain or a link is on the way or in place of the
     *     file, and with restore_write_failed when a folder or the part file cannot be made.
     * @throws \coding_exception When the path is open already.
     */
    public function open(string $path) {
        self::check_path($path);
        if (isset($this->open[$path])) {
            throw new \coding_exception('tree_writer: the file is open already');
        }
        [$folders, $name] = self::split($path);
        return $this->inside($folders, self::MAKE, function () use ($path, $name) {
            if (self::is(self::look($name), self::FOLDER)) {
                throw new plugin_exception('restore_write_failed');
            }
            $part = $name . self::PART;
            if (isset($this->committedparts[$path . self::PART])) {
                // A file of the site has the name: it is not removed to make room.
                throw new plugin_exception('restore_write_failed');
            }
            // A part file of an earlier restore that stopped. unlink() removes a link, not what it points to.
            self::remove_if($part, static fn(array $entry): bool => true);
            $umask = umask(0777 & ~$this->filemode);
            try {
                clearstatcache(true);
                $handle = error_silencer::run(static fn() => fopen($part, 'xb'), $warning);
            } finally {
                umask($umask);
            }
            if ($handle === false) {
                throw new plugin_exception('restore_write_failed');
            }
            $this->open[$path] = ['handle' => $handle] + self::node(fstat($handle));
            $this->expect($path, $part);
            return $handle;
        });
    }

    /**
     * Gives the file its name, after it has checked that the part file is still the one it opened.
     *
     * The file has had the mode of the writer since open(). When commit() fails, the path stays open: abort() removes the part
     * file.
     *
     * @param string $path The path given to open().
     * @throws plugin_exception With restore_path_unsafe when a link is on the way or in place of the file, or the part file is
     *     not the one it opened, and with restore_write_failed when a folder on the way is gone or the file cannot take its name.
     * @throws \coding_exception When the path is not open.
     */
    public function commit(string $path): void {
        if (!isset($this->open[$path])) {
            throw new \coding_exception('tree_writer: the file is not open');
        }
        [$folders, $name] = self::split($path);
        $this->inside($folders, self::NEED, function () use ($path, $name): void {
            $part = $name . self::PART;
            // Checked while the handle is open, so that no other file can have the inode of the part file.
            $this->expect($path, $part);
            self::close($this->open[$path]['handle']);
            self::look($name);
            if (!error_silencer::run(static fn() => rename($part, $name), $warning)) {
                throw new plugin_exception('restore_write_failed');
            }
            unset($this->open[$path]);
            if (str_ends_with($path, self::PART)) {
                $this->committedparts[$path] = true;
            }
        });
    }

    /**
     * Closes an open file and removes its part file, if the part file is still the one it opened.
     *
     * A path that is not open is left as it is. A folder on the way that is gone had the part file in it.
     *
     * @param string $path The path given to open().
     * @throws plugin_exception With restore_path_unsafe when a link is on the way, and with restore_write_failed when the part
     *     file cannot be removed.
     */
    public function abort(string $path): void {
        if (!isset($this->open[$path])) {
            return;
        }
        $file = $this->open[$path];
        unset($this->open[$path]);
        self::close($file['handle']);
        [$folders, $name] = self::split($path);
        $this->inside($folders, self::MAYBE, static function () use ($name, $file): void {
            self::remove_if($name . self::PART, static fn(array $entry): bool => self::same($entry, $file));
        });
    }

    /**
     * Runs a step in the last folder of a path, entered from the folder of the writer one name at a time.
     *
     * @param string[] $folders The folders of the path.
     * @param int $missing What a missing folder means: MAKE, NEED or MAYBE.
     * @param \Closure $step The step, run in the last folder.
     * @return mixed What the step returns, or null when a folder was missing (MAYBE only).
     * @throws plugin_exception With restore_write_failed when the folder of the writer cannot be entered.
     */
    private function inside(array $folders, int $missing, \Closure $step): mixed {
        $back = getcwd();
        try {
            if (!error_silencer::run(fn() => chdir($this->base), $warning)) {
                throw new plugin_exception('restore_write_failed');
            }
            foreach ($folders as $folder) {
                if (!$this->enter($folder, $missing)) {
                    return null;
                }
            }
            return $step();
        } finally {
            // A working folder that was removed has no path to come back to.
            error_silencer::run(static fn() => chdir($back === false ? '/' : $back), $warning);
        }
    }

    /**
     * Enters a folder of the working folder, after it has made it if it is missing and to be made.
     *
     * @param string $name The name of the folder.
     * @param int $missing What a missing folder means: MAKE, NEED or MAYBE.
     * @return bool False when the folder is missing (MAYBE only).
     * @throws plugin_exception With restore_path_unsafe when the name is a link or the folder entered is not the one looked at,
     *     and with restore_write_failed when the folder cannot be made or entered.
     */
    private function enter(string $name, int $missing): bool {
        $entry = self::entry($name);
        $made = false;
        if ($entry === null) {
            if ($missing === self::MAYBE) {
                return false;
            }
            if ($missing === self::NEED || !error_silencer::run(static fn() => mkdir($name, 0700), $warning)) {
                throw new plugin_exception('restore_write_failed');
            }
            $made = true;
            $entry = self::entry($name);
        }
        if (!error_silencer::run(static fn() => chdir($name), $warning)) {
            throw new plugin_exception(self::is($entry, self::LINK) ? 'restore_path_unsafe' : 'restore_write_failed');
        }
        // A link, or a folder swapped for one since the lstat(), has led to another folder. A folder made just now that does
        // not take its mode belongs to someone else, so it is not the one that was made.
        if (
            !self::same(self::entry('.'), $entry)
            || ($made && !error_silencer::run(fn() => chmod('.', $this->dirmode), $warning))
        ) {
            throw new plugin_exception('restore_path_unsafe');
        }
        return true;
    }

    /**
     * Refuses an entry of the working folder that is a link, and gives what lstat() says of it.
     *
     * @param string $name The name.
     * @return array|null The lstat() of the entry, or null when there is none.
     * @throws plugin_exception With restore_path_unsafe when it is a link.
     */
    private static function look(string $name): ?array {
        $entry = self::entry($name);
        if (self::is($entry, self::LINK)) {
            throw new plugin_exception('restore_path_unsafe');
        }
        return $entry;
    }

    /**
     * Refuses an open file whose part file is not the entry of the working folder with the device and inode it opened.
     *
     * @param string $path The path given to open().
     * @param string $part The name of the part file.
     * @throws plugin_exception With restore_path_unsafe.
     */
    private function expect(string $path, string $part): void {
        if (!self::same(self::entry($part), $this->open[$path])) {
            throw new plugin_exception('restore_path_unsafe');
        }
    }

    /**
     * Removes an entry of the working folder when it is there and a test says yes.
     *
     * @param string $name The name.
     * @param \Closure $test Takes the lstat() of the entry, says whether it is removed.
     * @throws plugin_exception With restore_write_failed when it cannot be removed.
     */
    private static function remove_if(string $name, \Closure $test): void {
        $entry = self::entry($name);
        if ($entry !== null && $test($entry) && !error_silencer::run(static fn() => unlink($name), $warning)) {
            throw new plugin_exception('restore_write_failed');
        }
    }

    /**
     * What lstat() says of an entry of the working folder, read again from the disk.
     *
     * @param string $name The name, or "." for the working folder itself.
     * @return array|null The lstat(), or null when there is no such entry.
     */
    private static function entry(string $name): ?array {
        clearstatcache(true);
        $entry = error_silencer::run(static fn() => lstat($name), $warning);
        return $entry === false ? null : $entry;
    }

    /**
     * Whether an entry is of a type.
     *
     * @param array|null $entry The lstat() of the entry, or null.
     * @param int $type LINK or FOLDER.
     * @return bool
     */
    private static function is(?array $entry, int $type): bool {
        return $entry !== null && ($entry['mode'] & self::TYPE) === $type;
    }

    /**
     * The device and inode of a stat().
     *
     * @param array $stat The stat().
     * @return int[] ['dev', 'ino']
     */
    private static function node(array $stat): array {
        return ['dev' => $stat['dev'], 'ino' => $stat['ino']];
    }

    /**
     * Whether two stat() name the same file.
     *
     * @param array|null $one A stat(), or null.
     * @param array|null $other Another one, or null.
     * @return bool False when one is null.
     */
    private static function same(?array $one, ?array $other): bool {
        return $one !== null && $other !== null && $one['dev'] === $other['dev'] && $one['ino'] === $other['ino'];
    }

    /**
     * Closes a handle that the caller has not closed.
     *
     * PHP writes plain files straight to the file descriptor, so the close loses nothing that fwrite() said it wrote.
     *
     * @param mixed $handle The handle, or a handle that is closed already.
     */
    private static function close(mixed $handle): void {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }

    /**
     * The folders and the name of a path.
     *
     * @param string $path The path.
     * @return array [string[] folders, string name]
     */
    private static function split(string $path): array {
        $folders = explode('/', $path);
        $name = array_pop($folders);
        return [$folders, $name];
    }
}
