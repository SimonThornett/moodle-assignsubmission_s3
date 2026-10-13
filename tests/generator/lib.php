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

use assignsubmission_s3\persistent\assignsubmission_s3 as s3_submission;

defined('MOODLE_INTERNAL') || die();

require_once("{$CFG->dirroot}/mod/assign/tests/generator/assignsubmission_subplugin_generator.php");

/**
 * S3 assignment submission subplugin data generator.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignsubmission_s3_generator extends assignsubmission_subplugin_generator {
    /**
     * Add submission data in the correct format for a call to `assign::save_submission()`.
     * For S3 tests we need a file and
     *
     * Data should be added to the $submission object passed into the function.
     *
     * @param stdClass $submission The submission record to be modified
     * @param assign $assign The assignment being submitted to
     * @param array $data The data received
     */
    public function add_submission_data(stdClass $submission, assign $assign, array $data): void {
        $currentsubmission = !empty($data['groupid']) ?
            $assign->get_group_submission($data['userid'], $data['groupid'], false) :
            $assign->get_user_submission($data['userid'], false);
        if ($currentsubmission) {
            foreach (s3_submission::get_records(['submission' => $currentsubmission->id]) as $record) {
                $record->delete();
            }
        }
        assign_submission_s3::create_submission($assign->get_course_module()->id, $data['filename'], $data['mimetype']);

        if (array_key_exists('groupid', $data)) {
            $submission->groupid = $data['groupid'];
        }
    }
}
