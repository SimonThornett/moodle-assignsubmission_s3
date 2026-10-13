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

namespace assignsubmission_s3\admin;

use admin_setting_configduration;

/**
 * Custom admin setting for duration as the core version includes seconds
 * and the minimum for AWS API requests is 1 day.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_config_duration_custom extends admin_setting_configduration {
    /** @var int default duration unit */
    protected $defaultunit;
    /** @var callable|null Validation function */
    protected $validatefunction = null;

    /** @var int The minimum allowed value */
    protected int $minduration = 0;

    /** @var null|int The maximum allowed value */
    protected null|int $maxduration = null;

    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config.
     * @param string $visiblename localised name
     * @param string $description localised long description
     * @param mixed $defaultsetting string or array depending on implementation
     * @param int $defaultunit - day, week, etc. (in seconds)
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $defaultunit = 86400) {
        if (is_number($defaultsetting)) {
            $defaultsetting = self::parse_seconds($defaultsetting);
        }
        $units = self::get_units();
        if (isset($units[$defaultunit])) {
            $this->defaultunit = $defaultunit;
        } else {
            $this->defaultunit = 86400;
        }
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Validate the setting. This uses the callback function if provided; subclasses could override
     * to carry out validation directly in the class.
     *
     * @param int $data New value being set
     * @return string Empty string if valid, or error message text
     * @since Moodle 3.10
     */
    protected function validate_setting(int $data): string {
        if ($data < $this->minduration) {
            return get_string(
                'configduration_low',
                'admin',
                self::get_duration_text($this->minduration, get_string('numseconds', 'core', 0))
            );
        }

        if ($this->maxduration && $data > $this->maxduration) {
            return get_string('configduration_high', 'admin', self::get_duration_text($this->maxduration));
        }

        // If validation function is specified, call it now.
        if ($this->validatefunction) {
            return call_user_func($this->validatefunction, $data);
        }
        return '';
    }

    /**
     * Returns selectable units.
     * @return array
     */
    protected static function get_units(): array {
        return [
            604800 => get_string('weeks'),
            86400 => get_string('days'),
        ];
    }

    /**
     * Converts seconds to some more user friendly string.
     *
     * @param int $seconds
     * @param null|string $emptyvalue The value to use when the duration is empty. If not specified, a "None" value is used.
     * @return string
     */
    protected static function get_duration_text(int $seconds, ?string $emptyvalue = null): string {
        if (empty($seconds)) {
            if ($emptyvalue !== null) {
                return $emptyvalue;
            }
            return get_string('none');
        }
        $data = self::parse_seconds($seconds);
        return match ($data['u']) {
            60 * 60 * 24 * 7 => get_string('numweeks', '', $data['v']),
            60 * 60 * 24 => get_string('numdays', '', $data['v']),
            default => get_string('numseconds', '', $data['v'] * $data['u']),
        };
    }

    /**
     * Returns duration text+select fields.
     *
     * @param mixed $data Array in the form 'v'=>xx, 'u'=>xx, or null when unset
     * @param mixed $query Search query
     * @return string duration text+select fields and wrapping div(s)
     */
    public function output_html(mixed $data, mixed $query = ''): string {
        $default = $this->get_defaultsetting();
        if (is_number($default)) {
            $defaultinfo = self::get_duration_text($default);
        } else if (is_array($default)) {
            $defaultinfo = self::get_duration_text($default['v'] * $default['u']);
        } else {
            $defaultinfo = null;
        }

        $units = array_filter(self::get_units(), function (int $unit): bool {
            if (!$this->maxduration) {
                // No duration limit. All units are valid.
                return true;
            }

            return $unit <= $this->maxduration;
        }, ARRAY_FILTER_USE_KEY);

        return unit_setting_output::output_html(
            $this,
            $data,
            $units,
            $this->defaultunit,
            $defaultinfo,
            $query,
        );
    }
}
