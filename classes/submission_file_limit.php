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

namespace assignsubmission_s3;

use assign;
use assign_submission_s3;
use assignsubmission_s3\persistent\assignsubmission_s3 as s3_submission;
use context_module;
use stdClass;

/**
 * Validates the site-wide maximum number of files per S3 submission.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_file_limit {
    /**
     * Get the configured maximum file count.
     *
     * @return int
     */
    /**
     * Check if another file can be added to the current submission.
     *
     * @param int $assignmentid
     * @return string
     */
    public static function validate(int $assignmentid): string {
        $submission = self::get_current_submission($assignmentid);
        if (!$submission) {
            return '';
        }

        $records = s3_submission::get_records(['submission' => $submission->id]);
        $maxfiles = assign_submission_s3::get_maxfiles($assignmentid);
        if (count($records) >= $maxfiles) {
            return get_string('error:maxfiles', 'assignsubmission_s3', $maxfiles);
        }

        return '';
    }

    /**
     * Remove every existing S3 file before the start of a replacement batch.
     *
     * @param int $assignmentid
     * @return void
     */
    public static function replace_existing_files(int $assignmentid): void {
        $submission = self::get_current_submission($assignmentid);
        if (!$submission) {
            return;
        }

        foreach (s3_submission::get_records(['submission' => $submission->id]) as $record) {
            $record->delete();
        }
    }

    /**
     * Get the current user's assignment submission, if one exists.
     *
     * @param int $assignmentid
     * @return stdClass|false
     */
    private static function get_current_submission(int $assignmentid): stdClass|false {
        global $USER;

        $context = context_module::instance($assignmentid);
        $assignment = new assign($context, null, null);
        if ($assignment->get_instance()->teamsubmission) {
            return $assignment->get_group_submission($USER->id, 0, false) ?: false;
        }

        return $assignment->get_user_submission($USER->id, false) ?: false;
    }
}
