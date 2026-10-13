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
use assignsubmission_s3\persistent\assignsubmission_s3_requests;
use assignsubmission_s3\tests\submission_testcase;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use context_course;
use DateTime;
use mod_assign_testable_assign;
use phpunit_event_sink;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/tests/generator.php');

/**
 * Object restore test.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \assignsubmission_s3\task\object_restore
 */
final class object_restore_test extends submission_testcase {
    /**
     * The event sink.
     *
     * @var phpunit_event_sink $eventsink
     */
    private phpunit_event_sink $eventsink;

    /**
     * The assign module.
     *
     * @var mod_assign_testable_assign
     */
    private mod_assign_testable_assign $assign;

    public function test_task_empty(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_graded_submission();
        // Execute the task and capture the output.
        ob_start();
        $task = new object_restore();
        $task->execute();
        $output = ob_get_clean();

        // We haven't requested a file yet, so there should be no output.
        $this->assertEmpty($output);
    }

    public function test_task_config_issue(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a "requested" submission.
        $this->create_graded_submission(assignsubmission_s3::STATUS_REMOTE_REQUESTED);

        // Execute the task and capture the output.
        ob_start();
        $task = new object_restore();
        $task->execute();
        $output = ob_get_clean();

        $this->assertEquals("Permission check failed: Region/Bucket/KeyID/Secret not defined in config.\n", $output);

        $triggeredevents = $this->eventsink->get_events();

        // Event 0 should be assessable_uploaded.
        // Event 1 should be assignsubmission_s3\event\submission_created.
        // Event 2 should be user_graded.
        // Event 3 should be user_graded again.
        // Event 4 should be submission_graded.
        // Event 5 should be the connection_issue.
        $this->assertEquals(6, $this->eventsink->count());

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

        // Create a "requested" submission.
        $this->create_graded_submission(assignsubmission_s3::STATUS_REMOTE_REQUESTED);

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
        $task = new object_restore();
        $task->execute($handler);
        $output = ob_get_clean();

        $this->assertEquals("Permission check failed: The specified bucket does not exist\n", $output);

        $triggeredevents = $this->eventsink->get_events();

        // Event 0 should be assessable_uploaded.
        // Event 1 should be assignsubmission_s3\event\submission_created.
        // Event 2 should be user_graded.
        // Event 3 should be user_graded again.
        // Event 4 should be submission_graded.
        // Event 5 should be the connection_issue.
        $this->assertEquals(6, $this->eventsink->count());

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

    public function test_valid_initial_restore_request(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a "requested" submission.
        $submission = $this->create_graded_submission(assignsubmission_s3::STATUS_REMOTE_REQUESTED);

        // Define the mock handler for the task.
        $handler = new MockHandler();
        // Return a empty result to pass permission check.
        $handler->append(new Result([]));
        // Return a empty result for restoreObject request accepted.
        $handler->append(new Result([]));
        // Return restore header ongoing.
        $handler->append(new Result(['Restore' => 'ongoing-request="true"']));

        // Set some fake global config to pass the "configured" check.
        set_config('region', 'fake-region', 'assignsubmission_s3');
        set_config('bucket', 'fake-bucket', 'assignsubmission_s3');
        set_config('secret', 'fake-secret', 'assignsubmission_s3');
        set_config('key', 'fake-key', 'assignsubmission_s3');

        // Execute the task and capture the output, passing the handler.
        ob_start();
        $task = new object_restore();
        $task->execute($handler);
        $output = ob_get_clean();

        $this->assertEquals("Object: {$submission->uuid} restore in progress\n", $output);
    }

    public function test_valid_final_restore_request(): void {
        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a "requested" submission.
        $submission = $this->create_graded_submission(assignsubmission_s3::STATUS_REMOTE_REQUESTED);

        // Define the mock handler for the task.
        $handler = new MockHandler();
        // Return a empty result to pass permission check.
        $handler->append(new Result([]));
        // Return restore header complete with expiry date a week from now to pass valid checks.
        $expirydate = date('D, d M Y', time() + WEEKSECS) . ' 00:00:00 GMT';
        $handler->append(new Result([
            'Restore' => 'ongoing-request="false", expiry-date="' . $expirydate . '"',
            'Expires' => $expirydate,
        ]));

        // Set some fake global config to pass the "configured" check.
        set_config('region', 'fake-region', 'assignsubmission_s3');
        set_config('bucket', 'fake-bucket', 'assignsubmission_s3');
        set_config('secret', 'fake-secret', 'assignsubmission_s3');
        set_config('key', 'fake-key', 'assignsubmission_s3');

        // Execute the task passing the handler, and capture the output and messages.
        $messagesink = $this->redirectMessages();
        ob_start();
        $task = new object_restore();
        $task->execute($handler);
        $output = ob_get_clean();

        $messages = $messagesink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals('restored', $messages[0]->eventtype);
        $messagesink->close();
    }

    /**
     * Create a graded submission for testing.
     *
     * @param int $status
     * @return stdClass
     */
    private function create_graded_submission(int $status = assignsubmission_s3::STATUS_REMOTE_STANDARD): stdClass {
        global $DB;

        $fixture = $this->create_submission_fixture();
        $course = $fixture->course;
        $user = $fixture->user;
        $this->assign = $fixture->assign;

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $teacherrole = $DB->get_record('role', ['shortname' => 'teacher']);
        $coursecontext = context_course::instance($course->id);
        $this->getDataGenerator()->role_assign($teacherrole->id, $teacher->id, $coursecontext->id);

        // Capture the events.
        $this->eventsink = $this->redirectEvents();

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
        $submission->status = $status;
        $DB->update_record(assignsubmission_s3::TABLE, $submission);

        // If it's a requested submission, add a requester.
        if ($status === assignsubmission_s3::STATUS_REMOTE_REQUESTED) {
            $request = new assignsubmission_s3_requests();
            $request->set_many([
                'ass3id' => $submission->id,
                'requester' => $teacher->id,
            ]);
            $request->save();
        }

        $this->mark_submission($teacher, $this->assign, $user, 100.0);

        return $submission;
    }
}
