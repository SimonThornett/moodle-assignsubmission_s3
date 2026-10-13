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

use assignsubmission_s3\admin\admin_setting_config_size;
use assignsubmission_s3\event\assessable_uploaded;
use assignsubmission_s3\event\submission_created;
use assignsubmission_s3\event\submission_updated;
use assignsubmission_s3\form\size;
use assignsubmission_s3\persistent\assignsubmission_s3;
use assignsubmission_s3\s3;
use assignsubmission_s3\submission_settings;
use core_form\filetypes_util;

/**
 * Library class for S3 submission plugin extending submission plugin base class.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_s3 extends assign_submission_plugin {
    /**
     * The file component to store file records in.
     */
    public const FILECOMPONENT = 'assignsubmission_s3';

    /**
     * The filearea to store file records in.
     */
    public const FILEAREA = 'submission_s3';

    /**
     * The size of the chunks we are uploading.
     */
    public const FILECHUNKS = 10 * 1024 * 1024;

    /**
     * The name of the submission plugin.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('s3', 'assignsubmission_s3');
    }

    /**
     * Get the form elements for the user to submit their file.
     *
     * @param mixed $submissionorgrade
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @param int $userid User submitting the file
     * @return void
     */
    public function get_form_elements_for_user($submissionorgrade, MoodleQuickForm $mform, stdClass $data, $userid): void {
        global $OUTPUT, $PAGE;

        $cmid = $this->assignment->get_course_module()->id;
        $maxfiles = self::get_maxfiles($cmid);
        $maxsize = self::get_maxfilesize($cmid);
        $PAGE->requires->js_call_amd(
            'assignsubmission_s3/upload',
            'init',
            [
                $cmid,
                self::FILECHUNKS,
                $maxfiles,
            ]
        );
        $mform->addElement(
            'html',
            $OUTPUT->render_from_template(
                'assignsubmission_s3/form/file',
                [
                    'accept' => implode(',', self::get_filetypes($cmid)),
                    'humansize' => self::get_human_readable_size($maxsize),
                    'maxfiles' => $maxfiles,
                    'multiple' => $maxfiles > 1,
                    'acceptedtypes' => get_string('form:acceptedtypes', 'assignsubmission_s3'),
                    'maxfilesize' => get_string('form:maxfilesize', 'assignsubmission_s3'),
                    'maxfileslabel' => get_string('form:maxfiles', 'assignsubmission_s3'),
                ]
            ),
        );
    }

    /**
     * Settings form elements for the assignment settings page.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform): void {
        global $OUTPUT;

        if (!$this->add_connection_settings($mform)) {
            return;
        }

        $this->add_submission_settings($mform);

        $mform->hideIf('allowsubmissionsfromdate', 'assignsubmission_s3_enabled', 'checked');
        $notification = $mform->createElement(
            'static',
            's3fromdatenotification',
            '',
            $OUTPUT->notification(
                get_string('activitysetting:noallowsubmissionsfromdate', 'assignsubmission_s3'),
                'info',
            )
        );
        $mform->insertElementBefore($notification, 'allowsubmissionsfromdate');
        $mform->hideIf('s3fromdatenotification', 'assignsubmission_s3_enabled');
    }

    /**
     * Add global credential notices or assignment-level connection settings.
     *
     * @param MoodleQuickForm $mform
     * @return bool Whether the connection settings are sufficiently configured.
     */
    private function add_connection_settings(MoodleQuickForm $mform): bool {
        global $OUTPUT;

        if (submission_settings::are_global_credentials_forced()) {
            $credentials = submission_settings::get_credentials($this->assignment);
            if (
                empty($credentials['region']) || empty($credentials['bucket']) ||
                    empty($credentials['secret']) || empty($credentials['key'])
            ) {
                $mform->addElement(
                    'static',
                    'misconfigured',
                    '',
                    $OUTPUT->notification(get_string('error:misconfigured', 'assignsubmission_s3'), 'error'),
                );
                return false;
            }
            // Static elements do not support hideIf, so wrap the notice in a group.
            $group = [
                $mform->createElement(
                    'static',
                    'assignsubmission_s3_label',
                    '',
                    $OUTPUT->notification(get_string('activitysetting:globalwarning', 'assignsubmission_s3'), 'info'),
                ),
            ];
            $mform->addGroup($group, 's3labelgroup', '', ' ', false);
            $mform->hideIf('s3labelgroup', 'assignsubmission_s3_enabled');
            return true;
        }

        $this->add_connection_overrides($mform);
        return true;
    }

    /**
     * Add connection overrides according to the current user's configuration capability.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function add_connection_overrides(MoodleQuickForm $mform): void {
        $canconfigure = $this->can_configure();
        $settings = [
            'baseurl' => PARAM_URL,
            'bucket' => PARAM_TEXT,
            'region' => PARAM_ALPHANUM,
            'key' => PARAM_ALPHANUM,
            'secret' => PARAM_ALPHANUM,
        ];
        foreach ($settings as $name => $paramtype) {
            if ($name !== 'baseurl' && !$canconfigure) {
                continue;
            }
            $mform->addElement(
                $name === 'secret' ? 'passwordunmask' : 'text',
                $name,
                get_string('activitysetting:' . $name, 'assignsubmission_s3'),
                ['maxlength' => 255, 'size' => 50],
            );
            $mform->setType($name, $paramtype);
            $mform->setDefault($name, $this->get_config($name) ?: '');
            $mform->addHelpButton($name, 'activitysetting:' . $name, 'assignsubmission_s3');
            $mform->hideIf($name, 'assignsubmission_s3_enabled');
        }
        if (!$canconfigure) {
            $mform->freeze(['baseurl']);
        }
    }

    /**
     * Add assignment-level overrides for submission limits.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    private function add_submission_settings(MoodleQuickForm $mform): void {
        global $OUTPUT;

        if (submission_settings::are_global_settings_forced()) {
            $group = [];
            $group[] = $mform->createElement(
                'static',
                'assignsubmission_s3_submission_label',
                '',
                $OUTPUT->notification(get_string('activitysetting:globalsubmissionwarning', 'assignsubmission_s3'), 'info')
            );
            $mform->addGroup($group, 's3submissionlabelgroup', '', ' ', false);
            $mform->hideIf('s3submissionlabelgroup', 'assignsubmission_s3_enabled');
            return;
        }

        $cm = $this->assignment->get_course_module();
        $cmid = $cm ? (int) $cm->id : null;
        $defaultmaxfiles = $this->assignment->has_instance() && $this->get_config('maxfiles') !== false ?
            (int) $this->get_config('maxfiles') : self::get_maxfiles($cmid);
        $defaultmaxsize = $this->assignment->has_instance() && $this->get_config('maxsize') !== false ?
            (int) $this->get_config('maxsize') : self::get_maxfilesize($cmid);

        $filesname = 'assignsubmission_s3_maxfiles';
        $mform->addElement(
            'text',
            $filesname,
            get_string('activitysetting:maxfiles', 'assignsubmission_s3'),
            ['size' => 5],
        );
        $mform->setType($filesname, PARAM_INT);
        $mform->setDefault($filesname, $defaultmaxfiles);
        $mform->addHelpButton($filesname, 'activitysetting:maxfiles', 'assignsubmission_s3');
        $mform->hideIf($filesname, 'assignsubmission_s3_enabled');
        $mform->addRule($filesname, null, 'numeric', null, 'client');

        size::register();
        $sizename = 'assignsubmission_s3_maxsize';
        $mform->addElement(
            'assignsubmission_s3_size',
            $sizename,
            get_string('activitysetting:maxsize', 'assignsubmission_s3'),
            ['size' => 5],
        );
        $mform->setDefault($sizename, $defaultmaxsize);
        $mform->addHelpButton($sizename, 'activitysetting:maxsize', 'assignsubmission_s3');
        $mform->hideIf($sizename, 'assignsubmission_s3_enabled');

        $filetypesname = 'assignsubmission_s3_filetypes';
        $mform->addElement('filetypes', $filetypesname, get_string('activitysetting:filetypes', 'assignsubmission_s3'));
        $mform->addHelpButton($filetypesname, 'activitysetting:filetypes', 'assignsubmission_s3');
        $setting = $this->assignment->has_instance() ? $this->get_config('filetypes') : false;
        $mform->setDefault(
            $filetypesname,
            $setting === false ? get_config('assignsubmission_s3', 'filetypes') : $setting,
        );
        $mform->hideIf($filetypesname, 'assignsubmission_s3_enabled');
    }

    /**
     * Check if the current user can configure the plugin in the provided context.
     *
     * @return bool
     */
    public function can_configure(): bool {
        global $PAGE;

        return has_capability('assignsubmission/s3:configure', $PAGE->context);
    }

    /**
     * Validate and save the form settings.
     *
     * @param stdClass $formdata
     * @return bool
     */
    public function save_settings(stdClass $formdata): bool {
        global $DB;

        $instance = $this->assignment->get_instance();
        // Update the instance with the 14 day window for submissions from date using due date.
        if ($instance->duedate > 0) {
            $instance->allowsubmissionsfromdate = $instance->duedate - (2 * WEEKSECS);
            $DB->update_record('assign', $instance);
        }

        if (!submission_settings::are_global_credentials_forced() && $this->can_configure()) {
            foreach (['baseurl', 'bucket', 'region', 'key', 'secret'] as $name) {
                if (isset($formdata->$name)) {
                    $this->set_config($name, $formdata->$name);
                }
            }
        }
        if (!submission_settings::are_global_settings_forced()) {
            $this->set_config('maxfiles', max(1, (int) $formdata->assignsubmission_s3_maxfiles));
            $maxsize = $formdata->assignsubmission_s3_maxsize ?? 0;
            if (is_object($maxsize)) {
                $maxsize = (array) $maxsize;
            }
            if (is_array($maxsize)) {
                $number = (int) ($maxsize['number'] ?? $maxsize['v'] ?? 0);
                $unit = (int) ($maxsize['unit'] ?? $maxsize['u'] ?? admin_setting_config_size::DEFAULT_MAXBYTESUNIT);
                $maxsize = $number * $unit;
            }
            $this->set_config('maxsize', max(1, (int) $maxsize));
            $this->set_config('filetypes', $formdata->assignsubmission_s3_filetypes ?? '');
        }
        return true;
    }

    /**
     * Save any custom data for this form submission.
     * As we would have already processed the file, this is just event triggers.
     *
     * @param stdClass $submissionorgrade
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submissionorgrade, stdClass $data): bool {
        global $USER, $DB;

        // Trigger all events.
        $params = [
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submissionorgrade->id,
            'other' => [
                'content' => '',
                'pathnamehashes' => [],
            ],
        ];
        if (!empty($submissionorgrade->userid) && ($submissionorgrade->userid != $USER->id)) {
            $params['relateduserid'] = $submissionorgrade->userid;
        }
        if ($this->assignment->is_blind_marking()) {
            $params['anonymous'] = 1;
        }
        $event = assessable_uploaded::create($params);
        $event->trigger();

        $groupname = null;
        $groupid = 0;
        // Get the group name as other fields are not transcribed in the logs and this information is important.
        if (empty($submissionorgrade->userid) && !empty($submissionorgrade->groupid)) {
            $groupname = $DB->get_field('groups', 'name', ['id' => $submissionorgrade->groupid], MUST_EXIST);
            $groupid = $submissionorgrade->groupid;
        } else {
            $params['relateduserid'] = $submissionorgrade->userid;
        }

        // Unset the objectid and other field from params for use in submission events.
        unset($params['objectid']);
        unset($params['other']);
        $params['other'] = [
            'submissionid' => $submissionorgrade->id,
            'submissionattempt' => $submissionorgrade->attemptnumber,
            'submissionstatus' => $submissionorgrade->status,
            'groupid' => $groupid,
            'groupname' => $groupname,
        ];

        $filesubmissions = $this->get_file_submissions($submissionorgrade->id);
        foreach ($filesubmissions as $filesubmission) {
            if ($filesubmission->get('status') === assignsubmission_s3::STATUS_REMOTE_STANDARD) {
                // If the status is remote then we're updating an existing submission.
                $filesubmission->save();
                $params['objectid'] = $filesubmission->get('id');
                $event = submission_updated::create($params);
            } else {
                // Otherwise it's a new file we've processed, so set the status to now be remote.
                $filesubmission->set('status', assignsubmission_s3::STATUS_REMOTE_STANDARD);
                $filesubmission->save();
                $params['objectid'] = $filesubmission->get('id');
                $event = submission_created::create($params);
            }
            $event->set_assign($this->assignment);
            $event->trigger();
        }
        return !empty($filesubmissions);
    }

    /**
     * Remove the submission.
     *
     * @param stdClass $submission
     * @return void
     * @throws coding_exception
     */
    public function remove(stdClass $submission): void {
        // Delete the custom table reference which triggers the deletion of the file in the bucket and stub files.
        foreach ($this->get_file_submissions($submission->id) as $s3submission) {
            $s3submission->delete();
        }
    }

    /**
     * Return the file(s) for the users submission.
     *
     * @param stdClass $submissionorgrade
     * @param stdClass $user
     * @return array
     */
    public function get_files(stdClass $submissionorgrade, stdClass $user): array {
        $result = [];
        $fs = get_file_storage();

        $files = $fs->get_area_files(
            $this->assignment->get_context()->id,
            self::FILECOMPONENT,
            self::FILEAREA,
            $submissionorgrade->id,
            'timemodified, id',
            false
        );

        $filenamecounts = [];
        foreach ($files as $file) {
            $filenamecounts[$file->get_filename()] = ($filenamecounts[$file->get_filename()] ?? 0) + 1;
        }

        foreach ($files as $file) {
            $filename = $file->get_filename();
            if ($filenamecounts[$filename] > 1) {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $filename = pathinfo($filename, PATHINFO_FILENAME) . ' (' . $file->get_id() . ')' .
                    ($extension === '' ? '' : '.' . $extension);
            }
            $result[$filename . '.txt'] = $file;
        }
        return $result;
    }

    /**
     * The assignment has been deleted - remove the plugin specific data
     *
     * @return bool
     */
    public function delete_instance(): bool {
        $records = assignsubmission_s3::get_records(['assignment' => $this->assignment->get_course_module()->id]);
        foreach ($records as $record) {
            $record->delete();
        }
        return true;
    }

    /**
     * Return if the plugin is in use or not.
     *
     * @param stdClass $submissionorgrade
     * @return bool
     */
    public function is_empty(stdClass $submissionorgrade): bool {
        $records = assignsubmission_s3::get_records(['assignment' => $this->assignment->get_course_module()->id]);
        return empty($records);
    }

    /**
     * Return true if there was no S3 submission.
     *
     * @param stdClass $data
     * @return bool
     */
    public function submission_is_empty(stdClass $data): bool {
        global $USER;

        $records = assignsubmission_s3::get_records([
            'usermodified' => $USER->id,
            'assignment' => $this->assignment->get_course_module()->id,
        ]);
        return empty($records);
    }

    /**
     * Get file submission information from the database.
     *
     * @param int $submissionid
     * @return assignsubmission_s3[]
     */
    private function get_file_submissions(int $submissionid): array {
        return assignsubmission_s3::get_records(['submission' => $submissionid]);
    }

    /**
     * Return the view summary for the
     *
     * @param stdClass $submissionorgrade
     * @param bool $showviewlink Whether to show a link to the full submission
     * @return string
     */
    public function view_summary(stdClass $submissionorgrade, &$showviewlink): string {
        return $this->assignment->render_area_files('assignsubmission_s3', self::FILEAREA, $submissionorgrade->id);
    }

    /**
     * Validate the submissions size and type
     *
     * @param int $cmid Assignment course module ID
     * @param string $filename
     * @param string $filesize
     * @return string
     */
    public static function validate_submission(int $cmid, string $filename, string $filesize): string {
        if (!self::is_allowed_filesize((int) $filesize, $cmid)) {
            return get_string(
                'error:filesize',
                'assignsubmission_s3',
                self::get_human_readable_size(self::get_maxfilesize($cmid)),
            );
        }
        if (!self::is_allowed_filetype($filename, $cmid)) {
            return get_string('error:filetype', 'assignsubmission_s3', $filename);
        }
        return '';
    }

    /**
     * Generate the stub file and assignsubmission_s3 record.
     *
     * @param int $assignmentid
     * @param string $filename
     * @param string $mimetype
     * @return assignsubmission_s3
     */
    public static function create_submission(int $assignmentid, string $filename, string $mimetype): assignsubmission_s3 {
        global $USER;

        $context = context_module::instance($assignmentid);
        $assignment = new assign($context, null, null);

        // Get the users submission (creating one if one doesn't exist).
        $instance = $assignment->get_instance();

        if ($instance->teamsubmission) {
            $submission = $assignment->get_group_submission($USER->id, 0, true);
        } else {
            $submission = $assignment->get_user_submission($USER->id, true);
        }
        $fs = get_file_storage();
        $s3submission = new assignsubmission_s3();
        $s3submission->set_many([
            'assignment' => $assignmentid,
            'submission' => $submission->id,
        ]);
        $s3submission->save();

        // Place each stub in its UUID directory so filenames need not be unique.
        $filerecord = new stdClass();
        $filerecord->contextid = $context->id;
        $filerecord->component = self::FILECOMPONENT;
        $filerecord->filearea = self::FILEAREA;
        $filerecord->itemid = $submission->id;
        $filerecord->filepath = '/' . $s3submission->get('uuid') . '/';
        $filerecord->filename = $filename;
        $filerecord->mimetype = $mimetype;

        $message = get_string(
            'file:string',
            'assignsubmission_s3',
            (new moodle_url('/mod/assign/view.php', ['id' => $assignmentid, 'action' => 'grading']))->out(false),
        );
        $file = $fs->create_file_from_string($filerecord, $message);
        $s3submission->set('fileid', $file->get_id());
        $s3submission->save();

        return $s3submission;
    }

    /**
     * Generate the pre-signed url.
     *
     * @param int $assignmentid
     * @param assignsubmission_s3 $s3submission
     * @param int $filesize
     * @param null $handler
     * @return array An array of an error string and/or the pre-signed url and upload ID.
     * @throws coding_exception
     */
    public static function generate_pre_signed(
        int $assignmentid,
        assignsubmission_s3 $s3submission,
        int $filesize,
        $handler = null
    ): array {
        $context = context_module::instance($assignmentid);
        $assignment = new assign($context, null, null);

        $s3 = new s3($assignment, $handler);
        // If the connection failed (due to permissions or config) return an error.
        if ($s3->has_error()) {
            return [
                $s3->get_error(),
                [],
                '',
            ];
        }
        $s3urls = [];
        $parts = ceil($filesize / self::FILECHUNKS);
        $key = $s3submission->get('uuid');
        for ($partnumber = 1; $partnumber <= $parts; $partnumber++) {
            $request = $s3->create_presigned_part_upload_requests(
                $key,
                $partnumber,
            );
            $s3urls[] = (string)$request->getUri();
        }

        return [
            '',
            $s3urls,
            $s3->get_mulitpart_upload_id($key),
        ];
    }

    /**
     * Get the max allowed filesize for the upload.
     *
     * @param ?int $cmid
     * @return int
     */
    public static function get_maxfiles(?int $cmid = null): int {
        return submission_settings::get_maxfiles($cmid);
    }

    /**
     * Get the effective maximum file size in bytes.
     *
     * @param ?int $cmid
     * @return int
     */
    public static function get_maxfilesize(?int $cmid = null): int {
        return submission_settings::get_maxsize($cmid);
    }

    /**
     * Get the configured filetypes normalized.
     *
     * @param ?int $cmid
     * @return array
     */
    public static function get_filetypes(?int $cmid = null): array {
        return submission_settings::get_filetypes($cmid);
    }

    /**
     * Get the human readable size of the bytes provided.
     *
     * @param int $size
     * @return string
     */
    public static function get_human_readable_size(int $size): string {
        $i = floor(log($size) / log(1024));
        $sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        return sprintf('%.02F', $size / pow(1024, $i)) * 1 . ' ' . $sizes[$i];
    }

    /**
     * Check if the file provided is of the right type to be uploaded.
     *
     * @param string $filename
     * @param ?int $cmid
     * @return bool
     */
    public static function is_allowed_filetype(string $filename, ?int $cmid = null): bool {
        return (new filetypes_util())->is_allowed_file_type($filename, self::get_filetypes($cmid));
    }

    /**
     * Check if the file provided is of the right size to be uploaded.
     *
     * @param int $filesize
     * @param ?int $cmid
     * @return bool
     */
    public static function is_allowed_filesize(int $filesize, ?int $cmid = null): bool {
        return $filesize <= self::get_maxfilesize($cmid);
    }
}
