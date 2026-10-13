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

namespace assignsubmission_s3\event;

use assignsubmission_s3\tests\submission_testcase;

/**
 * Submission created event test.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \assignsubmission_s3\event\assessable_uploaded
 */
final class assessable_uploaded_test extends submission_testcase {
    /**
     * Uploading a file emits an assessable event with the submission ID.
     */
    public function test_trigger(): void {

        // Initial setup.
        $this->resetAfterTest();
        $this->setAdminUser();

        $fixture = $this->create_submission_fixture();
        $assign = $fixture->assign;
        $user = $fixture->user;

        // Capture the events.
        $sink = $this->redirectEvents();

        // Create a submission to trigger the event.
        $this->create_s3_submission($assign, $user);

        $triggeredevents = $sink->get_events();

        // Event 0 should be assessable_uploaded.
        // Event 1 should be assignsubmission_s3\event\submission_created.
        $this->assertEquals(2, $sink->count());

        $event = $triggeredevents[0];
        $this->assertInstanceOf(assessable_uploaded::class, $event);
        $this->assertEquals($assign->get_context(), $event->get_context());
        $this->assertEventContextNotUsed($event);
        $description = 'The user with id "' .
            $user->id .
            '" has uploaded a file to the submission with id "' .
            $assign->get_user_submission($user->id, false)->id .
            '" in the assignment activity with course module id "' .
            $assign->get_course_module()->id .
            '"';
        $this->assertEquals($description, $event->get_description());
    }
}
