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

use assign;
use context_module;
use core_form\filetypes_util;

/**
 * Resolves effective site-wide and assignment-level submission settings and credentials.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_settings {
    /**
     * Return whether global connection credentials are enforced.
     *
     * @return bool
     */
    public static function are_global_credentials_forced(): bool {
        $forceglobal = get_config('assignsubmission_s3', 'forceglobalcredentials');
        return $forceglobal === false || !empty($forceglobal);
    }

    /**
     * Resolve connection credentials using the global credentials policy.
     *
     * @param assign $assignment
     * @return array
     */
    public static function get_credentials(assign $assignment): array {
        $globalconfig = (array) get_config('assignsubmission_s3');
        $instanceconfig = self::are_global_credentials_forced() || !$assignment->has_instance() ?
            [] : (array) $assignment->get_submission_plugin_by_type('s3')->get_config();
        $credentials = [];
        foreach (['baseurl', 'region', 'bucket', 'key', 'secret'] as $name) {
            $credentials[$name] = $instanceconfig[$name] ?? $globalconfig[$name] ?? '';
        }
        return $credentials;
    }

    /**
     * Get the maximum number of files for an assignment.
     *
     * @param ?int $cmid
     * @return int
     */
    public static function get_maxfiles(?int $cmid = null): int {
        return max(1, (int) self::get('maxfiles', $cmid));
    }

    /**
     * Get the maximum size in bytes for an assignment.
     *
     * @param ?int $cmid
     * @return int
     */
    public static function get_maxsize(?int $cmid = null): int {
        return (int) self::get('maxsize', $cmid);
    }

    /**
     * Get the accepted file types for an assignment.
     *
     * @param ?int $cmid
     * @return array
     */
    public static function get_filetypes(?int $cmid = null): array {
        return (new filetypes_util())->normalize_file_types(self::get('filetypes', $cmid));
    }

    /**
     * Return whether global submission settings are enforced, defaulting to enabled.
     *
     * @return bool
     */
    public static function are_global_settings_forced(): bool {
        $forceglobal = get_config('assignsubmission_s3', 'forceglobalsubmission');
        return $forceglobal === false || !empty($forceglobal);
    }

    /**
     * Get an assignment override when enabled, otherwise return the global setting.
     *
     * @param string $name
     * @param ?int $cmid
     * @return mixed
     */
    private static function get(string $name, ?int $cmid = null): mixed {
        if (!self::are_global_settings_forced() && !empty($cmid)) {
            $assignment = new assign(context_module::instance($cmid), null, null);
            $plugin = $assignment->get_submission_plugin_by_type('s3');
            $value = $plugin->get_config($name);
            if ($value !== false) {
                return $value;
            }
        }

        return get_config('assignsubmission_s3', $name);
    }
}
