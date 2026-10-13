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

use assignsubmission_s3\persistent\assignsubmission_s3;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->dirroot . '/mod/assign/submission/s3/locallib.php');

/**
 * Provides the information to restore S3 submission files.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignsubmission_s3_subplugin extends restore_subplugin {
    /** @var array Submissions whose stub files have already been restored. */
    private array $restoredfiles = [];

    /** @var array File links to restore after Moodle has restored the related files. */
    private array $pendingfilelinks = [];

    /**
     * Returns the paths to be handled by the subplugin at workshop level
     * @return array
     */
    protected function define_submission_subplugin_structure(): array {
        $paths = [];

        $elename = $this->get_namefor('submission');
        $elepath = $this->get_pathfor('/submission_s3');
        $paths[] = new restore_path_element($elename, $elepath);

        return $paths;
    }

    /**
     * Processes one submission_s3 element
     * @param mixed $data
     * @return void
     */
    public function process_assignsubmission_s3_submission(mixed $data): void {
        $data = (object)$data;
        $data->assignment = $this->get_new_parentid('assign');
        $oldsubmissionid = $data->submission;
        $data->submission = $this->get_mappingid('submission', $data->submission);

        // The UUID must be unique, as such we'll create a new UUID for the restore, and call AWS to copy the existing.
        $olduuid = $data->uuid;
        unset($data->uuid);
        $s3submission = new assignsubmission_s3(record: $data);
        $s3submission->save();
        $context = context_module::instance($data->assignment);
        $assignment = new assign($context, null, null);
        $s3 = new \assignsubmission_s3\s3($assignment);
        if (!$s3->has_error()) {
            $s3->copy_object($olduuid, $s3submission->get('uuid'));
        }

        if (!isset($this->restoredfiles[$oldsubmissionid])) {
            $this->add_related_files(
                'assignsubmission_s3',
                assign_submission_s3::FILEAREA,
                'submission',
                null,
                $oldsubmissionid,
            );
            $this->restoredfiles[$oldsubmissionid] = true;
        }

        $this->pendingfilelinks[] = [
            'id' => $s3submission->get('id'),
            'olduuid' => $olduuid,
        ];
    }

    /**
     * Restore persistent mappings after Moodle has restored the related stub files.
     *
     * @return void
     */
    protected function after_restore_submission(): void {
        foreach ($this->pendingfilelinks as $link) {
            $s3submission = new assignsubmission_s3($link['id']);
            $context = $s3submission->get_assign()->get_context();
            $files = get_file_storage()->get_area_files(
                $context->id,
                assign_submission_s3::FILECOMPONENT,
                assign_submission_s3::FILEAREA,
                $s3submission->get('submission'),
                'id',
                false,
            );
            $matchingfiles = array_filter($files, static function ($file) use ($link, $s3submission): bool {
                $matchespath = $file->get_filepath() === '/' . $link['olduuid'] . '/';
                return $matchespath;
            });
            if (empty($matchingfiles) && count($files) === 1) {
                $matchingfiles = $files;
            }
            if (count($matchingfiles) === 1) {
                $file = reset($matchingfiles);
                $s3submission->set('fileid', $file->get_id());
                $s3submission->save();
            }
        }
    }
}
