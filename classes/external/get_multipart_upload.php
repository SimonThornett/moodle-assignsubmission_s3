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
use assign_submission_s3;
use assignsubmission_s3\submission_file_limit;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External service to get a generated pre-signed S3 URL.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_multipart_upload extends external_api {
    /**
     * Describes the parameters for update_category_order webservice.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The course module ID'),
            'filename' => new external_value(PARAM_TEXT, 'The file name'),
            'mimetype' => new external_value(PARAM_TEXT, 'The file mime type'),
            'filesize' => new external_value(PARAM_TEXT, 'The file size'),
            'replaceexisting' => new external_value(PARAM_BOOL, 'Replace all existing submission files'),
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
            's3urls' => new external_multiple_structure(
                new external_value(PARAM_URL, 'Pre-signed S3 URLs.', VALUE_OPTIONAL),
            ),
            'uploadid' => new external_value(PARAM_TEXT, 'The generated upload ID', VALUE_OPTIONAL),
            'fileid' => new external_value(PARAM_INT, 'The Moodle file ID', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Execute the webservice call.
     *
     * @param int $cmid
     * @param string $filename
     * @param string $mimetype
     * @param string $filesize
     * @param bool $replaceexisting Whether to replace all existing submission files
     * @return array
     */
    public static function execute(
        int $cmid,
        string $filename,
        string $mimetype,
        string $filesize,
        bool $replaceexisting,
    ): array {
        [
            'cmid' => $cmid,
            'filename' => $filename,
            'mimetype' => $mimetype,
            'filesize' => $filesize,
            'replaceexisting' => $replaceexisting,
        ] = self::validate_parameters(
            self::execute_parameters(),
            [
                'cmid' => $cmid,
                'filename' => $filename,
                'mimetype' => $mimetype,
                'filesize' => $filesize,
                'replaceexisting' => $replaceexisting,
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
                's3urls' => [],
                'uploadid' => '',
                'fileid' => 0,
            ];
        }

        $plugin = $assign->get_submission_plugin_by_type('s3');

        if (!$plugin->get_config('enabled')) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => get_string('error:pluginnotenabled', 'assignsubmission_s3'),
                's3urls' => [],
                'uploadid' => '',
                'fileid' => 0,
            ];
        }

        $error = assign_submission_s3::validate_submission($cmid, $filename, $filesize);

        if (!empty($error)) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => $error,
                's3urls' => [],
                'uploadid' => '',
                'fileid' => 0,
            ];
        }

        if ($replaceexisting) {
            submission_file_limit::replace_existing_files($cmid);
        }

        $error = submission_file_limit::validate($cmid);
        if (!empty($error)) {
            return [
                'error' => true,
                'error_title' => get_string('error'),
                'error_msg' => $error,
                's3urls' => [],
                'uploadid' => '',
                'fileid' => 0,
            ];
        }

        $submission = assign_submission_s3::create_submission($cmid, $filename, $mimetype);

        [$error, $s3urls, $uploadid] = assign_submission_s3::generate_pre_signed($cmid, $submission, $filesize);

        return [
            'error' => !empty($error),
            'error_title' => get_string('error'),
            'error_msg' => $error,
            's3urls' => $s3urls,
            'uploadid' => $uploadid,
            'fileid' => (int) $submission->get('fileid'),
        ];
    }
}
