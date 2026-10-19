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

namespace tool_secbackup\local\backup\files;

use tool_secbackup\local\crypto\chunk_info;
use tool_secbackup\local\error_silencer;
use tool_secbackup\local\plugin_exception;

/**
 * Lists the files of the file store by content hash and opens them for reading.
 *
 * The file store is only ever read. A file is opened through the file API (get_content_file_handle() of its stored file,
 * after is_file_readable_remotely_by_storedfile() has said yes), which also works with an external file system class; that
 * path never recovers a file from the trash. A file that is not in the file directory but in the trash is read in place.
 *
 * @package    tool_secbackup
 * @copyright  2026 Shinonome Laboratory LLC.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_source {
    /** @var int[] Seconds to wait before each new try of a file that cannot be read. */
    private const WAITS = [1, 5, 15];

    /** @var \moodle_database The connection that the list of files is read from. */
    private \moodle_database $db;

    /** @var callable Waits some seconds. */
    private $sleep;

    /**
     * Constructor.
     *
     * @param \moodle_database $db The connection that the list of files is read from: the one of the export, so that the
     *     list is that of the snapshot of the database.
     * @param callable|null $sleep Called with the seconds to wait; sleep() by default.
     */
    public function __construct(\moodle_database $db, ?callable $sleep = null) {
        $this->db = $db;
        $this->sleep = $sleep ?? 'sleep';
    }

    /**
     * The next contents of the file store, in the order of the content hash.
     *
     * @param string|null $after The contents with a larger content hash; null for the first.
     * @param int $limit The most contents to give.
     * @return array[] ['contenthash' => string, 'size' => int] for each content that a file of 1 byte or more has, once.
     */
    public function next(?string $after, int $limit): array {
        $params = [];
        $where = 'filesize > 0';
        if ($after !== null) {
            $where .= ' AND contenthash > ?';
            $params[] = $after;
        }
        $rows = $this->db->get_records_sql(
            'SELECT contenthash, MAX(filesize) AS size FROM {files} WHERE ' . $where
                . ' GROUP BY contenthash ORDER BY contenthash',
            $params,
            0,
            $limit
        );
        return array_values(array_map(
            static fn(\stdClass $row): array => ['contenthash' => $row->contenthash, 'size' => (int) $row->size],
            $rows
        ));
    }

    /**
     * Opens the content of a file for reading.
     *
     * A content whose rows are gone (the files were deleted after the snapshot of the run listed them) is looked for in the
     * trash only.
     *
     * @param string $contenthash Content hash.
     * @return array|null ['handle' => stream, 'where' => 'filedir' or 'trashdir'], or null when the content is in neither.
     * @throws plugin_exception With nfs_slow when the content is there but cannot be opened.
     */
    public function open(string $contenthash): ?array {
        $records = $this->db->get_records_select('files', 'contenthash = ? AND filesize > 0', [$contenthash], 'id', '*', 0, 1);
        if ($records === []) {
            $handle = $this->trash_handle($contenthash);
            return $handle === null ? null : ['handle' => $handle, 'where' => 'trashdir'];
        }
        $storage = get_file_storage();
        $file = $storage->get_file_instance(reset($records));
        if ($storage->get_file_system()->is_file_readable_remotely_by_storedfile($file)) {
            $handle = $this->handle_of($file);
            if ($handle === false) {
                throw new plugin_exception('nfs_slow');
            }
            return ['handle' => $handle, 'where' => 'filedir'];
        }
        $handle = $this->trash_handle($contenthash);
        return $handle === null ? null : ['handle' => $handle, 'where' => 'trashdir'];
    }

    /**
     * Opens a file and packs it, reading it again from its start when the read fails.
     *
     * After a failed read the file is tried again after 1, 5 and 15 seconds; when the fourth try fails too, the error is
     * nfs_slow. A content that is not the one that its hash names (tamper_detected) is not tried again.
     *
     * @param string $contenthash Content hash.
     * @param int $size Size in the files table.
     * @param packer $packer The packer.
     * @return chunk_info[]|null What packer::add() gives, or null when the content is neither in the file directory nor in the
     *     trash.
     * @throws plugin_exception With nfs_slow, tamper_detected or temp_write_failed.
     */
    public function pack(string $contenthash, int $size, packer $packer): ?array {
        for ($attempt = 0;; $attempt++) {
            $handle = null;
            try {
                $opened = $this->open($contenthash);
                if ($opened === null) {
                    return null;
                }
                $handle = $opened['handle'];
                return $packer->add($contenthash, $handle, $size);
            } catch (plugin_exception $e) {
                if ($e->errorcode !== 'nfs_slow' || !isset(self::WAITS[$attempt])) {
                    throw $e;
                }
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
            ($this->sleep)(self::WAITS[$attempt]);
        }
    }

    /**
     * Opens the content of a stored file in the file directory, without letting PHP report a warning.
     *
     * A test replaces this to make the reading fail.
     *
     * @param \stored_file $file The file.
     * @return resource|false
     */
    protected function handle_of(\stored_file $file) {
        return error_silencer::run(static fn() => $file->get_content_file_handle(), $warning);
    }

    /**
     * Opens a file of the trash for reading, without letting PHP report a warning.
     *
     * A test replaces this to make the opening fail.
     *
     * @param string $path The path.
     * @return resource|false
     */
    protected function open_path(string $path) {
        return error_silencer::run(static fn() => fopen($path, 'rb'), $warning);
    }

    /**
     * Opens a content in the trash, where it is, without moving it.
     *
     * @param string $contenthash Content hash.
     * @return resource|null The stream, or null when the content is not in the trash.
     * @throws plugin_exception With nfs_slow when the content is there but cannot be opened.
     */
    private function trash_handle(string $contenthash): mixed {
        global $CFG;
        $trashdir = isset($CFG->trashdir) ? $CFG->trashdir : $CFG->dataroot . '/trashdir';
        $paths = [
            $trashdir . '/' . substr($contenthash, 0, 2) . '/' . substr($contenthash, 2, 2) . '/' . $contenthash,
            $trashdir . '/' . $contenthash,
        ];
        foreach ($paths as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $handle = $this->open_path($path);
            if ($handle === false) {
                throw new plugin_exception('nfs_slow');
            }
            return $handle;
        }
        return null;
    }
}
