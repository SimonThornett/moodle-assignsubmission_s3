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

namespace assignsubmission_s3\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/assign/submission/s3/locallib.php');

use assign;
use assignsubmission_s3\persistent\assignsubmission_s3;
use assignsubmission_s3\s3;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use Exception;

/**
 * External service to get a generated pre-signed S3 URL.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_multipart_complete extends external_api {
    /**
     * Describes the parameters for update_category_order webservice.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'fileid' => new external_value(PARAM_INT, 'The Moodle file ID'),
            'parts' => new external_value(PARAM_TEXT, 'JSON of all processed parts'),
            'uploadid' => new external_value(PARAM_TEXT, 'The Upload ID'),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'error' => new external_value(PARAM_BOOL, 'Was an error generated?', VALUE_REQUIRED),
            'error_title' => new external_value(PARAM_TEXT, 'Error title if error was generated', VALUE_OPTIONAL),
            'error_msg' => new external_value(PARAM_TEXT, 'Error message if error was generated', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Execute the webservice call.
     *
     * @param int $cmid
     * @param int $fileid
     * @param string $parts
     * @param string $uploadid
     * @return array
     */
    public static function execute(
        int $cmid,
        int $fileid,
        string $parts,
        string $uploadid,
    ): array {
        global $USER;

        [
            'cmid' => $cmid,
            'fileid' => $fileid,
            'parts' => $parts,
            'uploadid' => $uploadid,
        ] = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'fileid' => $fileid,
                'parts' => $parts,
                'uploadid' => $uploadid,
            ]
        );

        // Security checks.
        $context = context_module::instance($cmid);
        self::validate_context($context);
        require_capability('mod/assign:submit', $context);
        $assign = new assign($context, null, null);

        if (!$assign->submissions_open()) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => get_string('submissionnotopen', 'assign'),
            ];
        }

        $plugin = $assign->get_submission_plugin_by_type('s3');

        if (!$plugin->get_config('enabled')) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => get_string('error:pluginnotenabled', 'assignsubmission_s3'),
            ];
        }

        if ($assign->get_instance()->teamsubmission) {
            $submissionrecord = $assign->get_group_submission($USER->id, 0, false);
        } else {
            $submissionrecord = $assign->get_user_submission($USER->id, false);
        }
        if (!$submissionrecord) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => get_string('error:request', 'assignsubmission_s3'),
            ];
        }

        $s3submission = assignsubmission_s3::get_record([
            'submission' => $submissionrecord->id,
            'fileid' => $fileid,
        ]);
        if (!$s3submission) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => get_string('error:request', 'assignsubmission_s3'),
            ];
        }

        $errorstatus = ['error' => false];

        try {
            $s3 = new s3($assign, null);
            $s3->send_presigned_part_upload_complete_request($uploadid, $s3submission->get('uuid'), json_decode($parts, true));
        } catch (Exception $e) {
            $errorstatus = [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => $e->getMessage(),
            ];
        }
        return $errorstatus;
    }
}
