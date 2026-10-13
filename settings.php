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
 * Settings file.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use assignsubmission_s3\admin\admin_setting_config_duration_custom;
use assignsubmission_s3\admin\admin_setting_config_size;

require_once(dirname(__FILE__) . '/locallib.php');

$settings->add(
    new admin_setting_heading('connectionsettings', 'Connection settings', '')
);

$settings->add(
    new admin_setting_configcheckbox(
        'assignsubmission_s3/forceglobalcredentials',
        new lang_string('setting:forceglobalcredentials', 'assignsubmission_s3'),
        new lang_string('setting:forceglobalcredentials_help', 'assignsubmission_s3'),
        1,
    )
);

$settings->add(
    new admin_setting_configtext(
        'assignsubmission_s3/baseurl',
        new lang_string('setting:baseurl', 'assignsubmission_s3'),
        new lang_string('setting:baseurl_help', 'assignsubmission_s3'),
        '',
        PARAM_TEXT,
    )
);

$settings->add(
    new admin_setting_configtext(
        'assignsubmission_s3/region',
        new lang_string('setting:region', 'assignsubmission_s3'),
        new lang_string('setting:region_help', 'assignsubmission_s3'),
        '',
        PARAM_TEXT,
    )
);

$settings->add(
    new admin_setting_configtext(
        'assignsubmission_s3/bucket',
        new lang_string('setting:bucket', 'assignsubmission_s3'),
        new lang_string('setting:bucket_help', 'assignsubmission_s3'),
        '',
        PARAM_TEXT,
    )
);

$settings->add(
    new admin_setting_configpasswordunmask(
        'assignsubmission_s3/secret',
        new lang_string('setting:secret', 'assignsubmission_s3'),
        new lang_string('setting:secret_help', 'assignsubmission_s3'),
        '',
    )
);

$settings->add(
    new admin_setting_configtext(
        'assignsubmission_s3/key',
        new lang_string('setting:key', 'assignsubmission_s3'),
        new lang_string('setting:key_help', 'assignsubmission_s3'),
        '',
        PARAM_TEXT,
    )
);

$settings->add(
    new admin_setting_heading('glaciersettings', 'Glacier settings', '')
);

$settings->add(
    new admin_setting_configtext(
        'assignsubmission_s3/glaciertag',
        new lang_string('setting:glaciertag', 'assignsubmission_s3'),
        new lang_string('setting:glaciertag_help', 'assignsubmission_s3'),
        '',
        PARAM_TEXT,
    )
);

$settings->add(
    new admin_setting_config_duration_custom(
        'assignsubmission_s3/glacierrestoreduration',
        new lang_string('setting:glacierrestoreduration', 'assignsubmission_s3'),
        new lang_string('setting:glacierrestoreduration_help', 'assignsubmission_s3'),
        7 * DAYSECS,
    )
);

$settings->add(
    new admin_setting_heading('submissionsettings', 'Submission settings', '')
);

$settings->add(
    new admin_setting_configcheckbox(
        'assignsubmission_s3/default',
        new lang_string('setting:default', 'assignsubmission_s3'),
        new lang_string('setting:default_help', 'assignsubmission_s3'),
        0,
    )
);

$settings->add(
    new admin_setting_configcheckbox(
        'assignsubmission_s3/forceglobalsubmission',
        new lang_string('setting:forceglobalsubmission', 'assignsubmission_s3'),
        new lang_string('setting:forceglobalsubmission_help', 'assignsubmission_s3'),
        1,
    )
);

$settings->add(
    new admin_setting_configtext(
        'assignsubmission_s3/maxfiles',
        new lang_string('setting:maxfiles', 'assignsubmission_s3'),
        new lang_string('setting:maxfiles_help', 'assignsubmission_s3'),
        1,
        PARAM_INT,
    )
);

$settings->add(
    new admin_setting_filetypes(
        'assignsubmission_s3/filetypes',
        new lang_string('setting:acceptedfiletypes', 'assignsubmission_s3'),
        new lang_string('setting:acceptedfiletypes_help', 'assignsubmission_s3'),
        '',
    )
);

$settings->add(
    new admin_setting_config_size(
        'assignsubmission_s3/maxsize',
        new lang_string('setting:maxsize', 'assignsubmission_s3'),
        new lang_string('setting:maxsize_help', 'assignsubmission_s3'),
    )
);
