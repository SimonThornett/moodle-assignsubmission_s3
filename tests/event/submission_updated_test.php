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
 * Submission updated event test.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \assignsubmission_s3\event\submission_updated
 */
final class submission_updated_test extends submission_testcase {
    /**
     * Replacing an individual upload creates a new S3 record.
     */
    public function test_trigger_no_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->create_submission_fixture();
        $sink = $this->redirectEvents();
        $this->create_s3_submission($fixture->assign, $fixture->user);
        $this->create_s3_submission($fixture->assign, $fixture->user);

        // Replacement also emits a file deletion event before creating the new record.
        $this->assert_submission_created($sink, $fixture, 5);
    }

    /**
     * Replacing a group upload retains the group in its creation event.
     */
    public function test_trigger_group(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $fixture = $this->create_submission_fixture(['teamsubmission' => true]);
        $sink = $this->redirectEvents();
        $this->create_s3_submission($fixture->assign, $fixture->user, $fixture->group->id);
        $this->create_s3_submission($fixture->assign, $fixture->user);
        $this->assert_submission_created($sink, $fixture, 5);
    }
}
