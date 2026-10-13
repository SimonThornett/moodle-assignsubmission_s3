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

namespace assignsubmission_s3\task;

use assignsubmission_s3\persistent\assignsubmission_s3;
use assignsubmission_s3\tests\submission_testcase;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use DateTime;
use mod_assign_testable_assign;
use phpunit_event_sink;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Object tag test.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \assignsubmission_s3\task\object_tag
 */
final class object_tag_test extends submission_testcase {
    /**
     * The event sink.
     *
     * @var phpunit_event_sink
     */
    private phpunit_event_sink $sink;

    /**
     * The assign module.
     *
     * @var mod_assign_testable_assign
     */
    private mod_assign_testable_assign $assign;

    public function test_task_config_issue(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_graded_submission();
        // Execute the task and capture the output.
        ob_start();
        $task = new object_tag();
        $task->execute();
        $output = ob_get_clean();

        $this->assertEquals(
            "Unable to process tag for " .
            $this->assign->get_course_module()->id .
            " due to: Permission check failed: Region/Bucket/KeyID/Secret not defined in config.\n",
            $output,
        );

        $triggeredevents = $this->sink->get_events();

        // Event 0 should be assessable_uploaded.
        // Event 1 should be assignsubmission_s3\event\submission_created.
        // Event 2 should be user_graded.
        // Event 3 should be user_graded again.
        // Event 4 should be submission_graded.
        // Event 5 should be the connection_issue.
        $this->assertEquals(6, $this->sink->count());

        $event = $triggeredevents[5];
        $this->assertInstanceOf('\assignsubmission_s3\event\connection_issue', $event);
        $this->assertEquals($this->assign->get_context(), $event->get_context());
        $this->assertEventContextNotUsed($event);
        $description = 'The assignment activity with course module id "' .
            $this->assign->get_course_module()->id .
            '" was unable to connect with S3 due to the error: ' .
            '"Permission check failed: Region/Bucket/KeyID/Secret not defined in config."';
        $this->assertEquals($description, $event->get_description());
    }

    public function test_task_bucket_issue(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_graded_submission();
        // Define the mock handler for the task.
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd) {
            return new S3Exception('The specified bucket does not exist', $cmd);
        });

        // Set some fake global config to pass the "configured" check.
        set_config('region', 'fake-region', 'assignsubmission_s3');
        set_config('bucket', 'fake-bucket', 'assignsubmission_s3');
        set_config('secret', 'fake-secret', 'assignsubmission_s3');
        set_config('key', 'fake-key', 'assignsubmission_s3');

        // Execute the task and capture the output, passing the handler.
        ob_start();
        $task = new object_tag();
        $task->execute($handler);
        $output = ob_get_clean();

        $this->assertEquals(
            "Unable to process tag for " .
            $this->assign->get_course_module()->id .
            " due to: Permission check failed: The specified bucket does not exist\n",
            $output,
        );

        $triggeredevents = $this->sink->get_events();

        // Event 0 should be assessable_uploaded.
        // Event 1 should be assignsubmission_s3\event\submission_created.
        // Event 2 should be user_graded.
        // Event 3 should be user_graded again.
        // Event 4 should be submission_graded.
        // Event 5 should be the connection_issue.
        $this->assertEquals(6, $this->sink->count());

        $event = $triggeredevents[5];
        $this->assertInstanceOf('\assignsubmission_s3\event\connection_issue', $event);
        $this->assertEquals($this->assign->get_context(), $event->get_context());
        $this->assertEventContextNotUsed($event);
        $description = 'The assignment activity with course module id "' .
            $this->assign->get_course_module()->id .
            '" was unable to connect with S3 due to the error: ' .
            '"Permission check failed: The specified bucket does not exist"';
        $this->assertEquals($description, $event->get_description());
    }

    public function test_valid(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        $submission = $this->create_graded_submission();
        // Define the mock handler for the task.
        $handler = new MockHandler();
        // Pass the initial permission check.
        $handler->append(
            new Result([
                'ObjectURL' => 'https://test-object-url',
                '@metadata' => [
                    'statusCode' => 200,
                ],
            ]),
        );
        // Return a 200 for the valid object tagging.
        $handler->append(new Result([]));

        // Set some fake global config to pass the "configured" check.
        set_config('region', 'fake-region', 'assignsubmission_s3');
        set_config('bucket', 'fake-bucket', 'assignsubmission_s3');
        set_config('secret', 'fake-secret', 'assignsubmission_s3');
        set_config('key', 'fake-key', 'assignsubmission_s3');

        // Excute the task and capture the output, passing the handler.
        ob_start();
        $task = new object_tag();
        $task->execute($handler);
        $output = ob_get_clean();

        $this->assertEquals("Object: {$submission->uuid} tagged successfully\n", $output);
    }

    /**
     * Create a graded submission for testing.
     *
     * @return stdClass
     */
    private function create_graded_submission(): stdClass {
        global $DB;

        $fixture = $this->create_submission_fixture();
        $course = $fixture->course;
        $user = $fixture->user;
        $this->assign = $fixture->assign;
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        // Capture the events.
        $this->sink = $this->redirectEvents();

        // Create a submission to trigger the event.
        $this->create_s3_submission($this->assign, $user);

        // Get the assignment we just created.
        $s3submission = assignsubmission_s3::get_record(['usermodified' => $user->id]);
        // We need to update the DB record via the global DB as the timemodified field
        // doesn't use a dependency injectionable clock.
        $id = $s3submission->get('id');
        $submission = $DB->get_record(assignsubmission_s3::TABLE, ['id' => $id]);
        // Set the timemodified to 100 days ago to pass the 90 day check in the task.
        $submission->timemodified = (new DateTime('100 days ago'))->getTimestamp();
        $DB->update_record(assignsubmission_s3::TABLE, $submission);

        $this->mark_submission($teacher, $this->assign, $user, 100.0);

        return $submission;
    }
}
