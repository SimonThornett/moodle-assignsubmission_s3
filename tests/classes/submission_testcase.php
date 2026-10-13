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

namespace assignsubmission_s3\tests;

use advanced_testcase;
use assign;
use assignsubmission_s3\event\submission_created;
use mod_assign_test_generator;
use phpunit_event_sink;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Shared fixtures and assertions for S3 submission tests.
 *
 * @package   assignsubmission_s3
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class submission_testcase extends advanced_testcase {
    use mod_assign_test_generator;

    /**
     * Create an assignment with only S3 submissions enabled and an enrolled student.
     *
     * @param array $params Assignment settings
     * @return stdClass Assignment, course, user and optional group
     */
    protected function create_submission_fixture(array $params = []): stdClass {
        $fixture = new stdClass();
        $fixture->course = $this->getDataGenerator()->create_course();
        $fixture->user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($fixture->user->id, $fixture->course->id, 'student');
        $fixture->group = null;
        if (!empty($params['teamsubmission'])) {
            $fixture->group = $this->getDataGenerator()->create_group(['courseid' => $fixture->course->id]);
            $this->getDataGenerator()->create_group_member([
                'groupid' => $fixture->group->id,
                'userid' => $fixture->user->id,
            ]);
        }
        $fixture->assign = $this->create_instance($fixture->course, $params);
        foreach ($fixture->assign->get_submission_plugins() as $plugin) {
            if ($plugin->get_type() === 's3') {
                $plugin->enable();
            } else {
                $plugin->disable();
            }
        }
        return $fixture;
    }

    /**
     * Upload the standard test file through the assignment generator.
     *
     * @param assign $assign Assignment receiving the submission
     * @param stdClass $user Submitting student
     * @param int $groupid Group ID, or zero for an individual submission
     */
    protected function create_s3_submission(assign $assign, stdClass $user, int $groupid = 0): void {
        $data = [
            'cmid' => $assign->get_course_module()->id,
            'userid' => $user->id,
            's3' => true,
            'filename' => 'filename.txt',
            'mimetype' => 'plain/text',
            'filesize' => '40000000',
        ];
        if ($groupid) {
            $data['groupid'] = $groupid;
        }
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission($data);
    }

    /**
     * Assert the final creation event, including its group-aware description.
     *
     * @param phpunit_event_sink $sink Captured events
     * @param stdClass $fixture Submission fixture
     * @param int $eventcount Expected total number of events
     */
    protected function assert_submission_created(phpunit_event_sink $sink, stdClass $fixture, int $eventcount): void {
        $this->assertEquals($eventcount, $sink->count());
        $event = $sink->get_events()[$eventcount - 1];
        $this->assertInstanceOf(submission_created::class, $event);
        $this->assertEquals($fixture->assign->get_context(), $event->get_context());
        $this->assertEventContextNotUsed($event);
        $description = 'The user with id "' . $fixture->user->id .
            '" created a Large file submission and uploaded a file in the assignment with course module id "' .
            $fixture->assign->get_course_module()->id . '"';
        if ($fixture->group !== null) {
            $description .= ' for the group with id "' . $fixture->group->id . '"';
        }
        $this->assertEquals($description, $event->get_description());
    }
}
