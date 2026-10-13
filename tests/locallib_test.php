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

use advanced_testcase;
use assign;
use assign_submission_s3;
use assignsubmission_s3\admin\admin_setting_config_duration_custom;
use assignsubmission_s3\admin\admin_setting_config_size;
use assignsubmission_s3\form\size;
use assignsubmission_s3\persistent\assignsubmission_s3 as s3_submission;
use assignsubmission_s3\submission_file_limit;
use assignsubmission_s3\submission_settings;
use context_course;
use DOMDocument;
use mod_assign_test_generator;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');
require_once($CFG->dirroot . '/mod/assign/submission/s3/locallib.php');
require_once($CFG->dirroot . '/mod/assign/submission/s3/db/upgrade.php');

/**
 * Tests for S3 assignment submission settings and validation.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \assign_submission_s3
 */
final class locallib_test extends advanced_testcase {
    use mod_assign_test_generator;

    /**
     * The upload template supports unrestricted and restricted file selection safely.
     */
    public function test_upload_template_attributes(): void {
        global $OUTPUT, $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance(SITEID));
        foreach (['', '.pdf,.txt', 'text/plain" autofocus="autofocus'] as $accept) {
            $document = new DOMDocument();
            $document->loadHTML($OUTPUT->render_from_template('assignsubmission_s3/form/file', [
                'accept' => $accept,
                'multiple' => $accept !== '',
            ]));
            $input = $document->getElementById('s3_file');
            $this->assertNotNull($input);
            $this->assertSame($accept !== '', $input->hasAttribute('accept'));
            $this->assertSame($accept, $input->getAttribute('accept'));
            $this->assertSame($accept !== '', $input->hasAttribute('multiple'));
            $this->assertFalse($input->hasAttribute('size'));
            $this->assertFalse($input->hasAttribute('autofocus'));
            $progress = $document->getElementById('progress-bar');
            $this->assertNotNull($progress);
            $this->assertSame('progressbar', $progress->getAttribute('role'));
            $this->assertSame('progress-bar-percent', $progress->getAttribute('aria-describedby'));
            $this->assertSame('span', $document->getElementById('progress-bar-percent')->tagName);
        }
    }

    /**
     * Size and duration settings retain their values, units and default selection.
     *
     * @dataProvider unit_setting_provider
     * @covers \assignsubmission_s3\admin\unit_setting_output
     * @covers \assignsubmission_s3\admin\admin_setting_config_size
     * @covers \assignsubmission_s3\admin\admin_setting_config_duration_custom
     * @param string $type Setting type
     * @param array|null $data Value and unit to render
     * @param string $value Expected input value
     * @param int|null $selectedunit Expected selected unit, or null for an unset value
     * @param int $unitcount Number of available units
     */
    public function test_unit_setting_output(
        string $type,
        ?array $data,
        string $value,
        ?int $selectedunit,
        int $unitcount,
    ): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance(SITEID));
        $setting = $type === 'size' ?
            new admin_setting_config_size('assignsubmission_s3/maxsize', 'Size', 'File size') :
            new admin_setting_config_duration_custom('assignsubmission_s3/duration', 'Duration', 'Restore duration', DAYSECS);
        $document = new DOMDocument();
        $document->loadHTML($setting->output_html($data));
        $input = $document->getElementById($setting->get_id() . 'v');
        $this->assertNotNull($input);
        $this->assertSame($value, $input->getAttribute('value'));
        $this->assertSame($setting->get_full_name() . '[v]', $input->getAttribute('name'));
        $select = $document->getElementById($setting->get_id() . 'u');
        $this->assertNotNull($select);
        $this->assertSame($setting->get_full_name() . '[u]', $select->getAttribute('name'));
        $options = $select->getElementsByTagName('option');
        $this->assertSame($unitcount, $options->length);
        $selected = [];
        foreach ($options as $option) {
            if ($option->hasAttribute('selected')) {
                $selected[] = $option->getAttribute('value');
            }
        }
        $this->assertSame($selectedunit === null ? [] : [(string) $selectedunit], $selected);
    }

    /**
     * Values for the shared size and duration rendering.
     *
     * @return array
     */
    public static function unit_setting_provider(): array {
        $gb = admin_setting_config_size::SIZE_UNIT_GIGABYTE;
        $mb = admin_setting_config_size::SIZE_UNIT_MEGABYTE;
        return [
            'size value' => ['size', ['v' => 20, 'u' => $mb], '20', $mb, 4],
            'size zero' => ['size', ['v' => 0, 'u' => $gb], '0', $gb, 4],
            'size unset' => ['size', null, '', null, 4],
            'duration value' => ['duration', ['v' => 2, 'u' => WEEKSECS], '2', WEEKSECS, 2],
            'duration zero' => ['duration', ['v' => 0, 'u' => DAYSECS], '0', DAYSECS, 2],
            'duration unset' => ['duration', null, '', null, 2],
        ];
    }

    /**
     * File-count limits support duplicate filenames and replacement.
     */
    public function test_default_maxfiles_and_submission_file_count(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('maxfiles', 'assignsubmission_s3');

        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $assign = $this->create_instance($course);
        $this->setUser($user);

        $cmid = $assign->get_course_module()->id;
        $this->assertSame('', submission_file_limit::validate($cmid));
        assign_submission_s3::create_submission($cmid, 'first.txt', 'text/plain');

        $this->assertSame(
            get_string('error:maxfiles', 'assignsubmission_s3', 1),
            submission_file_limit::validate($cmid),
        );

        submission_file_limit::replace_existing_files($cmid);
        set_config('maxfiles', 2, 'assignsubmission_s3');
        $this->assertSame('', submission_file_limit::validate($cmid));
        assign_submission_s3::create_submission($cmid, 'second.txt', 'text/plain');
        assign_submission_s3::create_submission($cmid, 'second.txt', 'text/plain');
        $submission = $assign->get_user_submission($user->id, false);
        $this->assertCount(2, s3_submission::get_records([
            'submission' => $submission->id,
        ]));
        $this->assertCount(2, get_file_storage()->get_area_files(
            $assign->get_context()->id,
            assign_submission_s3::FILECOMPONENT,
            assign_submission_s3::FILEAREA,
            $submission->id,
            'id',
            false,
        ));
        $stubfiles = array_values(get_file_storage()->get_area_files(
            $assign->get_context()->id,
            assign_submission_s3::FILECOMPONENT,
            assign_submission_s3::FILEAREA,
            $submission->id,
            'id',
            false,
        ));
        $this->assertSame($stubfiles[0]->get_filename(), $stubfiles[1]->get_filename());
        $this->assertNotSame($stubfiles[0]->get_filepath(), $stubfiles[1]->get_filepath());
        $this->assertNotSame($stubfiles[0]->get_id(), $stubfiles[1]->get_id());
        foreach (s3_submission::get_records(['submission' => $submission->id]) as $record) {
            $this->assertNotFalse(get_file_storage()->get_file_by_id($record->get('fileid')));
        }
        $plugin = $assign->get_submission_plugin_by_type('s3');
        $this->assertCount(2, $plugin->get_files($submission, new stdClass()));
        $plugin->save($submission, new stdClass());
        foreach (s3_submission::get_records(['submission' => $submission->id]) as $record) {
            $this->assertSame(s3_submission::STATUS_REMOTE_STANDARD, $record->get('status'));
        }
        $this->assertSame(
            get_string('error:maxfiles', 'assignsubmission_s3', 2),
            submission_file_limit::validate($cmid),
        );
        $records = array_values(s3_submission::get_records(['submission' => $submission->id]));
        $firstfileid = $records[0]->get('fileid');
        $secondfileid = $records[1]->get('fileid');
        $records[0]->delete();
        $this->assertFalse(get_file_storage()->get_file_by_id($firstfileid));
        $this->assertFalse(get_file_storage()->get_file_by_id($secondfileid));
        $this->assertCount(0, get_file_storage()->get_area_files(
            $assign->get_context()->id,
            assign_submission_s3::FILECOMPONENT,
            assign_submission_s3::FILEAREA,
            $submission->id,
            'id',
            false,
        ));
    }

    /**
     * Assignment limits override site defaults unless forced globally.
     */
    public function test_assignment_submission_settings_overrides(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        unset_config('forceglobalsubmission', 'assignsubmission_s3');
        set_config('maxfiles', 3, 'assignsubmission_s3');
        set_config('maxsize', 4096, 'assignsubmission_s3');
        set_config('filetypes', 'txt', 'assignsubmission_s3');

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->create_instance($course);
        $cmid = $assign->get_course_module()->id;
        $plugin = $assign->get_submission_plugin_by_type('s3');

        $this->assertSame(3, assign_submission_s3::get_maxfiles($cmid));
        $this->assertSame(4096, assign_submission_s3::get_maxfilesize($cmid));
        $this->assertTrue(assign_submission_s3::is_allowed_filetype('document.txt', $cmid));
        $this->assertFalse(assign_submission_s3::is_allowed_filetype('document.pdf', $cmid));

        set_config('forceglobalsubmission', 0, 'assignsubmission_s3');
        $plugin->set_config('maxfiles', '2');
        $plugin->set_config('maxsize', '2048');
        $plugin->set_config('filetypes', 'pdf');

        $this->assertSame(2, assign_submission_s3::get_maxfiles($cmid));
        $this->assertSame(2048, assign_submission_s3::get_maxfilesize($cmid));
        $this->assertFalse(assign_submission_s3::is_allowed_filetype('document.txt', $cmid));
        $this->assertTrue(assign_submission_s3::is_allowed_filetype('document.pdf', $cmid));

        set_config('forceglobalsubmission', 1, 'assignsubmission_s3');
        $this->assertSame(3, assign_submission_s3::get_maxfiles($cmid));
        $this->assertSame(4096, assign_submission_s3::get_maxfilesize($cmid));
        $this->assertTrue(assign_submission_s3::is_allowed_filetype('document.txt', $cmid));
        $this->assertFalse(assign_submission_s3::is_allowed_filetype('document.pdf', $cmid));
    }

    /**
     * Connection credentials follow their own global policy and preserve explicit overrides.
     */
    public function test_connection_credentials_overrides(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $globalcredentials = [
            'baseurl' => 'https://global.example.test',
            'region' => 'global-region',
            'bucket' => 'global-bucket',
            'key' => 'global-key',
            'secret' => 'global-secret',
        ];
        foreach ($globalcredentials as $name => $value) {
            set_config($name, $value, 'assignsubmission_s3');
        }
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->create_instance($course);
        $plugin = $assignment->get_submission_plugin_by_type('s3');
        $plugin->set_config('bucket', 'assignment-bucket');
        $plugin->set_config('baseurl', '');

        unset_config('forceglobalcredentials', 'assignsubmission_s3');
        $this->assertSame($globalcredentials, submission_settings::get_credentials($assignment));

        set_config('forceglobalcredentials', 0, 'assignsubmission_s3');
        set_config('forceglobalsubmission', 1, 'assignsubmission_s3');
        $expected = $globalcredentials;
        $expected['baseurl'] = '';
        $expected['bucket'] = 'assignment-bucket';
        $this->assertSame($expected, submission_settings::get_credentials($assignment));

        set_config('forceglobalcredentials', 1, 'assignsubmission_s3');
        set_config('forceglobalsubmission', 0, 'assignsubmission_s3');
        $this->assertSame($globalcredentials, submission_settings::get_credentials($assignment));
    }

    /**
     * The config rename preserves both enabled and disabled values and is repeatable.
     */
    public function test_migrate_forceglobal_credentials(): void {
        $this->resetAfterTest();
        unset_config('forceglobal', 'assignsubmission_s3');
        unset_config('forceglobalcredentials', 'assignsubmission_s3');
        assignsubmission_s3_upgrade_rename_forceglobal();
        $this->assertFalse(get_config('assignsubmission_s3', 'forceglobalcredentials'));

        foreach (['0', '1'] as $value) {
            set_config('forceglobal', $value, 'assignsubmission_s3');
            assignsubmission_s3_upgrade_rename_forceglobal();
            $this->assertSame($value, get_config('assignsubmission_s3', 'forceglobalcredentials'));
            $this->assertFalse(get_config('assignsubmission_s3', 'forceglobal'));

            assignsubmission_s3_upgrade_rename_forceglobal();
            $this->assertSame($value, get_config('assignsubmission_s3', 'forceglobalcredentials'));
        }
    }

    /**
     * Connection form overrides and saving follow the renamed credential policy.
     */
    public function test_connection_settings_form_and_save(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('forceglobalcredentials', 0, 'assignsubmission_s3');
        set_config('forceglobalsubmission', 1, 'assignsubmission_s3');
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->create_instance($course);
        $PAGE->set_context($assignment->get_context());
        $plugin = $assignment->get_submission_plugin_by_type('s3');
        $form = new MoodleQuickForm('connectionsettings', 'post', '');
        $form->addElement('advcheckbox', 'assignsubmission_s3_enabled');
        $form->addElement('text', 'allowsubmissionsfromdate');
        $plugin->get_settings($form);
        foreach (['baseurl', 'bucket', 'region', 'key', 'secret'] as $name) {
            $this->assertTrue($form->elementExists($name));
        }
        $formdata = (object) [
            'baseurl' => 'https://assignment.example.test',
            'bucket' => 'assignment-bucket',
            'region' => 'assignment-region',
            'key' => 'assignment-key',
            'secret' => 'assignment-secret',
        ];
        $this->assertTrue($plugin->save_settings($formdata));
        foreach ((array) $formdata as $name => $value) {
            $this->assertSame($value, $plugin->get_config($name));
            set_config($name, $value, 'assignsubmission_s3');
        }

        set_config('forceglobalcredentials', 1, 'assignsubmission_s3');
        $forcedform = new MoodleQuickForm('forcedconnectionsettings', 'post', '');
        $forcedform->addElement('advcheckbox', 'assignsubmission_s3_enabled');
        $forcedform->addElement('text', 'allowsubmissionsfromdate');
        $plugin->get_settings($forcedform);
        $this->assertTrue($forcedform->elementExists('s3labelgroup'));
        $this->assertFalse($forcedform->elementExists('secret'));
        $this->assertTrue($plugin->save_settings(new stdClass()));
        $this->assertSame('assignment-secret', $plugin->get_config('secret'));

        set_config('forceglobalcredentials', 0, 'assignsubmission_s3');
        $this->setUser($this->getDataGenerator()->create_user());
        $restrictedform = new MoodleQuickForm('restrictedconnectionsettings', 'post', '');
        $restrictedform->addElement('advcheckbox', 'assignsubmission_s3_enabled');
        $restrictedform->addElement('text', 'allowsubmissionsfromdate');
        $plugin->get_settings($restrictedform);
        $this->assertTrue($restrictedform->getElement('baseurl')->isFrozen());
        $this->assertFalse($restrictedform->elementExists('secret'));
        $this->assertTrue($plugin->save_settings((object) ['secret' => 'unauthorised-change']));
        $this->assertSame('assignment-secret', $plugin->get_config('secret'));
    }

    /**
     * Settings form and limit helpers safely handle a new assignment instance without a course module.
     */
    public function test_settings_for_new_instance_without_course_module(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('forceglobalcredentials', 0, 'assignsubmission_s3');
        set_config('forceglobalsubmission', 0, 'assignsubmission_s3');
        set_config('maxfiles', 4, 'assignsubmission_s3');
        set_config('maxsize', 8192, 'assignsubmission_s3');
        set_config('filetypes', 'zip', 'assignsubmission_s3');
        set_config('baseurl', 'https://global.example.test', 'assignsubmission_s3');
        set_config('bucket', 'global-bucket', 'assignsubmission_s3');
        set_config('region', 'global-region', 'assignsubmission_s3');
        set_config('key', 'global-key', 'assignsubmission_s3');
        set_config('secret', 'global-secret', 'assignsubmission_s3');

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);
        $PAGE->set_context($coursecontext);

        $newassign = new assign($coursecontext, null, $course);
        $this->assertNull($newassign->get_course_module());
        $this->assertFalse($newassign->has_instance());

        $this->assertSame(4, assign_submission_s3::get_maxfiles(null));
        $this->assertSame(8192, assign_submission_s3::get_maxfilesize(null));
        $this->assertSame(['.zip'], assign_submission_s3::get_filetypes(null));
        $this->assertTrue(assign_submission_s3::is_allowed_filetype('archive.zip', null));
        $this->assertFalse(assign_submission_s3::is_allowed_filetype('archive.tar', null));
        $this->assertTrue(assign_submission_s3::is_allowed_filesize(8192, null));
        $this->assertFalse(assign_submission_s3::is_allowed_filesize(8193, null));

        $credentials = submission_settings::get_credentials($newassign);
        $this->assertSame('global-bucket', $credentials['bucket']);

        $plugin = $newassign->get_submission_plugin_by_type('s3');
        $form = new MoodleQuickForm('newassignmentsettings', 'post', '');
        $form->addElement('advcheckbox', 'assignsubmission_s3_enabled');
        $form->addElement('text', 'allowsubmissionsfromdate');
        $plugin->get_settings($form);

        $this->assertTrue($form->elementExists('assignsubmission_s3_maxfiles'));
        $this->assertTrue($form->elementExists('assignsubmission_s3_maxsize'));
        $this->assertTrue($form->elementExists('assignsubmission_s3_filetypes'));
        $this->assertTrue($form->elementExists('bucket'));

        set_config('forceglobalsubmission', 1, 'assignsubmission_s3');
        set_config('forceglobalcredentials', 1, 'assignsubmission_s3');
        $forcedform = new MoodleQuickForm('newassignmentforced', 'post', '');
        $forcedform->addElement('advcheckbox', 'assignsubmission_s3_enabled');
        $forcedform->addElement('text', 'allowsubmissionsfromdate');
        $plugin->get_settings($forcedform);

        $this->assertTrue($forcedform->elementExists('s3submissionlabelgroup'));
        $this->assertTrue($forcedform->elementExists('s3labelgroup'));
        $this->assertFalse($forcedform->elementExists('assignsubmission_s3_maxfiles'));
        $this->assertFalse($forcedform->elementExists('bucket'));
    }

    /**
     * Size form element supports number and unit, exports bytes, and saves settings.
     */
    public function test_size_form_element_and_settings_save(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('forceglobalsubmission', 0, 'assignsubmission_s3');
        set_config('forceglobalcredentials', 0, 'assignsubmission_s3');
        set_config('baseurl', 'https://global.example.test', 'assignsubmission_s3');
        set_config('bucket', 'global-bucket', 'assignsubmission_s3');
        set_config('region', 'global-region', 'assignsubmission_s3');
        set_config('key', 'global-key', 'assignsubmission_s3');
        set_config('secret', 'global-secret', 'assignsubmission_s3');

        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->create_instance($course);
        $PAGE->set_context($assignment->get_context());
        $plugin = $assignment->get_submission_plugin_by_type('s3');

        $form = new MoodleQuickForm('sizesettingsform', 'post', '');
        $form->addElement('advcheckbox', 'assignsubmission_s3_enabled');
        $form->addElement('text', 'allowsubmissionsfromdate');
        $plugin->get_settings($form);

        $sizeelement = $form->getElement('assignsubmission_s3_maxsize');
        $this->assertInstanceOf(size::class, $sizeelement);
        $elements = $sizeelement->getElements();
        $this->assertCount(2, $elements);
        $this->assertSame('number', $elements[0]->getName());
        $this->assertSame('unit', $elements[1]->getName());

        $submitvalues = [
            'assignsubmission_s3_maxsize' => [
                'number' => 20,
                'unit' => admin_setting_config_size::SIZE_UNIT_GIGABYTE,
            ],
        ];
        $this->assertSame(
            20 * admin_setting_config_size::SIZE_UNIT_GIGABYTE,
            $sizeelement->exportValue($submitvalues),
        );
        $this->assertNull($sizeelement->validateSubmitValue($submitvalues));

        $invalidvalues = [
            'assignsubmission_s3_maxsize' => [
                'number' => 0,
                'unit' => admin_setting_config_size::SIZE_UNIT_GIGABYTE,
            ],
        ];
        $this->assertSame(
            get_string('err_positiveint', 'core_form'),
            $sizeelement->validateSubmitValue($invalidvalues),
        );

        $formdata = (object) [
            'assignsubmission_s3_maxfiles' => 5,
            'assignsubmission_s3_maxsize' => [
                'number' => 20,
                'unit' => admin_setting_config_size::SIZE_UNIT_GIGABYTE,
            ],
            'assignsubmission_s3_filetypes' => 'zip',
        ];
        $this->assertTrue($plugin->save_settings($formdata));
        $this->assertSame(20 * admin_setting_config_size::SIZE_UNIT_GIGABYTE, (int) $plugin->get_config('maxsize'));

        $directdata = (object) [
            'assignsubmission_s3_maxfiles' => 5,
            'assignsubmission_s3_maxsize' => 1048576,
            'assignsubmission_s3_filetypes' => 'zip',
        ];
        $this->assertTrue($plugin->save_settings($directdata));
        $this->assertSame(1048576, (int) $plugin->get_config('maxsize'));
    }
}
