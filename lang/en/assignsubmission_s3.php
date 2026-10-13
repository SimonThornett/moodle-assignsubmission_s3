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
 * Language file.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['activitysetting:baseurl'] = 'Base URL';
$string['activitysetting:baseurl_help'] = 'Alternate URL for S3 compatible endpoints. Leave blank for normal S3 use';
$string['activitysetting:bucket'] = 'Bucket';
$string['activitysetting:bucket_help'] = 'Amazon S3 bucket to store files in';
$string['activitysetting:filetypes'] = 'Accepted file types';
$string['activitysetting:filetypes_help'] = 'Accepted file types can be restricted by entering a list of file extensions. If left empty, all file types are allowed.';
$string['activitysetting:globalsubmissionwarning'] = 'Large file submission settings have been set globally';
$string['activitysetting:globalwarning'] = 'S3 credentials have been set globally';
$string['activitysetting:key'] = 'Key';
$string['activitysetting:key_help'] = 'Amazon S3 key credential';
$string['activitysetting:maxfiles'] = 'Maximum files per submission';
$string['activitysetting:maxfiles_help'] = 'Maximum number of files students may upload for this assignment.';
$string['activitysetting:maxsize'] = 'Maximum submission size';
$string['activitysetting:maxsize_help'] = 'Files uploaded by students may be up to this size.';
$string['activitysetting:noallowsubmissionsfromdate'] = 'As Large file submissions is enabled "Allow submissions from date" will be set automatically to 14 days prior to due date';
$string['activitysetting:region'] = 'Region';
$string['activitysetting:region_help'] = 'Amazon S3 API gateway region';
$string['activitysetting:secret'] = 'Secret';
$string['activitysetting:secret_help'] = 'Amazon S3 secret credential';
$string['enabled'] = 'Large file submissions';
$string['enabled_help'] = 'If enabled, students are able to upload one or more files as their submission to Large file submission.';
$string['error:filesize'] = 'The max file size of {$a} has been exceeded.';
$string['error:filetype'] = 'The file {$a} is an invalid file type.';
$string['error:maxfiles'] = 'You can submit a maximum of {$a} files.';
$string['error:misconfigured'] = 'Force global is enabled, but the site settings are incorrect. Please contact the site administrator.';
$string['error:pluginnotenabled'] = 'This plugin is not enabled for the specified assignment';
$string['error:request'] = 'Unable to connect to S3, please contact the site administrator.';
$string['event:assessable_uploaded:description'] = 'The user with id "{$a->userid}" has uploaded a file to the submission with id "{$a->objectid}" in the assignment activity with course module id "{$a->contextinstanceid}"';
$string['event:connection_issue:description'] = 'The assignment activity with course module id "{$a->contextinstanceid}" was unable to connect with S3 due to the error: "{$a->error}"';
$string['event:object_requested:description'] = 'The user with id "{$a->userid}" has requested the file for the submission with id "{$a->objectid}" in the assignment activity with course module id "{$a->contextinstanceid}"';
$string['event:submission_created:description'] = 'The user with id "{$a->userid}" created a Large file submission and uploaded a file in the assignment with course module id "{$a->contextinstanceid}"';
$string['event:submission_created:description_group'] = 'The user with id "{$a->userid}" created a Large file submission and uploaded a file in the assignment with course module id "{$a->contextinstanceid}" for the group with id "{$a->groupid}"';
$string['event:submission_updated:description'] = 'The user with id "{$a->userid}" updated a Large file submission and uploaded a file in the assignment with course module id "{$a->contextinstanceid}"';
$string['event:submission_updated:description_group'] = 'The user with id "{$a->userid}" updated a Large file submission and uploaded a file in the assignment with course module id "{$a->contextinstanceid}" for the group with id "{$a->groupid}"';
$string['file:string'] = 'This file is a placeholder as large file submissions are stored in a seperate location which does not support bulk download. As such you will need to download/request the file from the submissions page here: {$a}';
$string['form:acceptedtypes'] = 'Accepted types:';
$string['form:maxfiles'] = 'Maximum files:';
$string['form:maxfilesize'] = 'Max file size:';
$string['glacier:already_requested'] = 'You have already requested for this submission file to be restored from the archive. You will be notified when it is ready.';
$string['glacier:already_restored'] = 'This submission file has already been restored. Please download it via the standard means.';
$string['glacier:confirm'] = 'This submission file has been transferred to archival storage. Requesting this file can take up to 48 hours and you will be notified when it is ready and for how long the file will be available. Additionally, requesting a file from archive will cause this institution to pay additional costs. Please confirm that you would like to proceed in restoring this submission';
$string['glacier:confirm:notified'] = 'This submission file has already been requested and will be restored from the archive in the next 48 hours. Would you like to be notified when the file is ready and for how long the file will be available?';
$string['glacier:heading'] = 'Restore from archive storage';
$string['glacier:permission_denied'] = 'This submission file has been transferred to archival storage. Unfortunately you do not have permission to restore this file.';
$string['glacier:requested'] = 'The submission file has been requested. You will be notified when it is ready for download.';
$string['message:restored:body'] = 'The file that you requested is now available for download. See further information and links below:
* Course: {$a->course}
* Assignment: {$a->assignment}
* File: <a href="{$a->link}">{$a->link}</a>
* Available until: {$a->expiry}
';
$string['message:restored:subject'] = 'Requested submission file is available for download.';
$string['messageprovider:restored'] = 'Notification submission file has been restored.';
$string['pluginname'] = 'Large file submissions';
$string['privacy:metadata:assignsubmission_s3'] = 'Assign Large file submission';
$string['privacy:metadata:assignsubmission_s3_requests'] = 'Assign Large File submission requests';
$string['privacy:metadata:filepurpose'] = 'The files loaded for this assignment submission';
$string['privacy:metadata:requester'] = 'Requester';
$string['privacy:metadata:timecreated'] = 'Time created';
$string['privacy:metadata:timemodified'] = 'Time modified';
$string['privacy:metadata:usermodified'] = 'User modified';
$string['s3'] = 'Large file submissions';
$string['s3:configure'] = 'Configure Large file submission';
$string['s3:glacier_request'] = 'Request a submission from Glacier archive storage';
$string['setting:acceptedfiletypes'] = 'Accepted file types';
$string['setting:acceptedfiletypes_help'] = 'Accepted file types can be restricted by entering a list of file extensions. If the field is left empty, then all file types are allowed.';
$string['setting:baseurl'] = 'Base URL';
$string['setting:baseurl_help'] = 'Alternate URL for S3 compatible endpoints. Leave blank for normal S3 use';
$string['setting:bucket'] = 'Bucket';
$string['setting:bucket_help'] = 'Amazon S3 bucket to store files in';
$string['setting:default'] = 'Enabled by default';
$string['setting:default_help'] = 'If set, this submission method will be enabled by default for all new assignments.';
$string['setting:forceglobalcredentials'] = 'Force global credentials';
$string['setting:forceglobalcredentials_help'] = 'Always use these credentials to connect to S3';
$string['setting:forceglobalsubmission'] = 'Force global submission settings';
$string['setting:forceglobalsubmission_help'] = 'Always use the global maximum files, accepted file types, and maximum file size. When disabled, these values can be overridden for each assignment.';
$string['setting:glacierrestoreduration'] = 'Glacier restore duration';
$string['setting:glacierrestoreduration_help'] = 'When an object is restored from Glacier storage it will only be available for the period defined here. This will also be included in the notification to the user once the file is available. Currently the minimum is 1 day.';
$string['setting:glaciertag'] = 'Glacier tag';
$string['setting:glaciertag_help'] = 'Tag to add to objects to move to glacier storage with AWS lifecycle policy';
$string['setting:key'] = 'Key';
$string['setting:key_help'] = 'Amazon S3 key credential';
$string['setting:maxfiles'] = 'Maximum files per submission';
$string['setting:maxfiles_help'] = 'The maximum number of files students can upload for each Large file submission. This site-wide limit must be at least 1.';
$string['setting:maxsize'] = 'Maximum submission size';
$string['setting:maxsize_help'] = 'Files uploaded by students may be up to this size.';
$string['setting:region'] = 'Region';
$string['setting:region_help'] = 'Amazon S3 API gateway region';
$string['setting:secret'] = 'Secret';
$string['setting:secret_help'] = 'Amazon S3 secret credential';
$string['size'] = 'Size';
$string['sizeunit'] = 'Size unit';
$string['task:object_restore'] = 'Glacier object restoring';
$string['task:object_tag'] = 'Object glacier tagging';
