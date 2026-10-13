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

namespace assignsubmission_s3\persistent;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use assign;
use assign_submission_s3;
use assignsubmission_s3\s3;
use context_module;
use core\persistent;
use core\uuid;

/**
 * Persistent class for assignsubmission_s3 table.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignsubmission_s3 extends persistent {
    /** @var string Table name this class is mapped to. */
    public const TABLE = 'assignsubmission_s3';

    /** @var int Status to indicate file is stored locally. */
    public const STATUS_LOCAL = 0;

    /** @var int Status to indicate file is stored in standard S3 storage. */
    public const STATUS_REMOTE_STANDARD = 1;

    /** @var int Status to indicate file is stored in S3 Glacier deep storage. */
    public const STATUS_REMOTE_GLACIER = 2;

    /** @var int Status to indicate file is stored in S3 Glacier deep storage and has been requested to be downloaded */
    public const STATUS_REMOTE_REQUESTED = 3;

    /** @var int Status to indicate file is stored in S3 Glacier deep storage and has been restored */
    public const STATUS_REMOTE_RESTORED = 4;

    /** @var array Local cache of assignments for performance. */
    public static array $assignments = [];

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'assignment' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'submission' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'fileid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'status' => [
                'type' => PARAM_INT,
                'default' => self::STATUS_LOCAL,
            ],
            'expiry' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'uuid' => [
                'type' => PARAM_TEXT,
                'default' => function () {
                    return self::generate_uuid();
                },
            ],
        ];
    }

    /**
     * Get a UUID that has not been used in the table already.
     * The chances of generating one that has already been used is infinitesimal, but never zero.
     *
     * @return string
     */
    public static function generate_uuid(): string {
        $uuid = uuid::generate();
        while (self::get_record(['uuid' => $uuid]) !== false) {
            $uuid = uuid::generate();
        }
        return $uuid;
    }

    /**
     * Returns all records modified before the specified time with the status "STATUS_REMOTE_STANDARD".
     *
     * @param int $time
     * @return array
     */
    public static function get_standard_graded_records_before(int $time): array {
        global $DB;

        $return = [];
        $cms = [];

        // Get all standard records since.
        $records = $DB->get_records_select(
            self::TABLE,
            'timemodified < ? AND status = ?',
            [
                $time,
                self::STATUS_REMOTE_STANDARD,
            ],
        );

        foreach ($records as $record) {
            if (!isset($cms[$record->assignment])) {
                [, $cm] = get_course_and_cm_from_cmid($record->assignment);
                $cms[$record->assignment] = $cm;
            }
            if (grade_is_user_graded_in_activity($cms[$record->assignment], $record->usermodified)) {
                $return[] = new static(0, $record);
            }
        }

        return $return;
    }

    /**
     * Get all records in the requested status.
     *
     * @return assignsubmission_s3[]
     */
    public static function get_requested(): array {
        return self::get_records(['status' => self::STATUS_REMOTE_REQUESTED]);
    }

    /**
     * Return boolean for if the the object is in glacier storage and requested.
     *
     * @return bool
     */
    public function is_requested(): bool {
        return $this->get('status') === self::STATUS_REMOTE_REQUESTED;
    }

    /**
     * Get all records in the restored status.
     *
     * @return assignsubmission_s3[]
     */
    public static function get_restored(): array {
        return self::get_records(['status' => self::STATUS_REMOTE_RESTORED]);
    }

    /**
     * Return boolean for if the the object has been restored.
     *
     * @return bool
     */
    public function is_restored(): bool {
        return $this->get('status') === self::STATUS_REMOTE_RESTORED;
    }

    /**
     * Return boolean for if the the object is in glacier storage.
     *
     * @return bool
     */
    public function is_glacier(): bool {
        return $this->get('status') === self::STATUS_REMOTE_GLACIER;
    }

    /**
     * Get the assign class for the record.
     *
     * @return assign
     */
    public function get_assign(): assign {

        if (isset(self::$assignments[$this->get('assignment')])) {
            return self::$assignments[$this->get('assignment')];
        }
        self::$assignments[$this->get('assignment')] = new assign(
            context_module::instance($this->get('assignment')),
            null,
            null,
        );
        return self::$assignments[$this->get('assignment')];
    }

    /**
     * After delete method to connect to AWS and remove the file from the bucket
     * as well as removing the stub file locally.
     *
     * @param bool $result Whether the database record was deleted successfully
     * @return void
     */
    public function after_delete($result) {
        $s3 = new s3($this->get_assign());
        // Only trigger the delete if we can connect to AWS.
        if (!$s3->has_error()) {
            $s3->delete_object($this->get('uuid'));
        }

        // Delete the stub file.
        $fs = get_file_storage();
        $fs->delete_area_files(
            $this->get_assign()->get_context()->id,
            'assignsubmission_s3',
            assign_submission_s3::FILEAREA,
            $this->get('submission'),
        );
    }
}
