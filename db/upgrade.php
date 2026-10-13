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

/**
 * Upgrade steps.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade this plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_assignsubmission_s3_upgrade(int $oldversion): bool {
    if ($oldversion < 2026100802) {
        assignsubmission_s3_upgrade_add_fileid();
        assignsubmission_s3_upgrade_map_fileids();
        assignsubmission_s3_upgrade_update_indexes();
        upgrade_plugin_savepoint(true, 2026100802, 'assignsubmission', 's3');
    }

    if ($oldversion < 2026100803) {
        assignsubmission_s3_upgrade_rename_forceglobal();
        upgrade_plugin_savepoint(true, 2026100803, 'assignsubmission', 's3');
    }

    return true;
}

/**
 * Rename the global credentials policy while preserving its configured value.
 *
 * @return void
 */
function assignsubmission_s3_upgrade_rename_forceglobal(): void {
    $forceglobal = get_config('assignsubmission_s3', 'forceglobal');
    if ($forceglobal !== false) {
        set_config('forceglobalcredentials', $forceglobal, 'assignsubmission_s3');
        unset_config('forceglobal', 'assignsubmission_s3');
    }
}

/**
 * Add the nullable file ID column used to identify stub files.
 *
 * @return void
 */
function assignsubmission_s3_upgrade_add_fileid(): void {
    global $DB;

    $table = new xmldb_table('assignsubmission_s3');
    $field = new xmldb_field('fileid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'submission');
    if (!$DB->get_manager()->field_exists($table, $field)) {
        $DB->get_manager()->add_field($table, $field);
    }
}

/**
 * Map existing S3 records to their Moodle stub files.
 *
 * @return void
 */
function assignsubmission_s3_upgrade_map_fileids(): void {
    global $DB;

    foreach ($DB->get_records('assignsubmission_s3') as $record) {
        $file = assignsubmission_s3_upgrade_find_file($record);
        if ($file) {
            $record->fileid = $file->get_id();
            $DB->update_record('assignsubmission_s3', $record);
        }
    }
}

/**
 * Find the stub file belonging to an existing S3 record.
 *
 * @param stdClass $record
 * @return stored_file|null
 */
function assignsubmission_s3_upgrade_find_file(stdClass $record): ?stored_file {
    $context = context_module::instance($record->assignment);
    $fs = get_file_storage();
    $files = $fs->get_area_files(
        $context->id,
        'assignsubmission_s3',
        'submission_s3',
        $record->submission,
        'id',
        false,
    );
    foreach ($files as $file) {
        if ($file->get_filepath() === '/' . $record->uuid . '/') {
            return $file;
        }
    }

    // The previous version stored one record and one stub file per submission.
    return count($files) === 1 ? reset($files) : null;
}

/**
 * Add a unique index on Moodle file IDs.
 *
 * @return void
 */
function assignsubmission_s3_upgrade_update_indexes(): void {
    global $DB;

    $table = new xmldb_table('assignsubmission_s3');
    $manager = $DB->get_manager();
    $fileidindex = new xmldb_index('fileid', XMLDB_INDEX_UNIQUE, ['fileid']);
    if (!$manager->index_exists($table, $fileidindex)) {
        $manager->add_index($table, $fileidindex);
    }
}
