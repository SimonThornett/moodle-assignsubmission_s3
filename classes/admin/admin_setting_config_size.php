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

use admin_setting;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Custom admin setting for duration as the core version includes seconds
 * and the minimum for AWS API requests is 1 day.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2025
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class admin_setting_config_size extends admin_setting {
    /** @var int default size unit */
    protected int $defaultunit;
    /**
     * Default max bytes value.
     */
    public const DEFAULT_MAXBYTESVALUE = 4;

    /**
     * Default max bytes unit.
     */
    public const DEFAULT_MAXBYTESUNIT = self::SIZE_UNIT_GIGABYTE;

    /**
     * File size KB.
     */
    public const SIZE_UNIT_KILOBYTE = 1024;

    /**
     * File size MB.
     */
    public const SIZE_UNIT_MEGABYTE = self::SIZE_UNIT_KILOBYTE * 1024;

    /**
     * File size GB.
     */
    public const SIZE_UNIT_GIGABYTE = self::SIZE_UNIT_MEGABYTE * 1024;

    /**
     * File size TB.
     */
    public const SIZE_UNIT_TERABYTE = self::SIZE_UNIT_GIGABYTE * 1024;

    /**
     * Constructor
     * @param string $name unique ascii name, either 'mysetting' for settings that in config.
     * @param string $visiblename localised name
     * @param string $description localised long description
     * @param int $defaultsetting default size
     * @param int $defaultunit - day, week, etc. (in seconds)
     */
    public function __construct(
        string $name,
        string $visiblename,
        string $description,
        int $defaultsetting = self::DEFAULT_MAXBYTESVALUE,
        int $defaultunit = self::DEFAULT_MAXBYTESUNIT,
    ) {
        $defaultsetting = self::parse_size($defaultsetting * $defaultunit);
        $units = self::get_units();
        if (isset($units[$defaultunit])) {
            $this->defaultunit = $defaultunit;
        } else {
            $this->defaultunit = self::DEFAULT_MAXBYTESUNIT;
        }
        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }


    /**
     * Finds suitable units for given size.
     *
     * @param int $bytes
     * @return array
     */
    public static function parse_size(int $bytes): array {
        if ($bytes <= 0) {
            return ['v' => 0, 'u' => self::DEFAULT_MAXBYTESUNIT];
        }
        $units = array_flip(array_reverse(self::get_units(), true));

        foreach ($units as $unit) {
            if ($bytes % $unit === 0) {
                return ['v' => (int) ($bytes / $unit), 'u' => $unit];
            }
        }
        return ['v' => $bytes, 'u' => self::SIZE_UNIT_KILOBYTE];
    }

    /**
     * Returns selectable units.
     *
     * @return array
     */
    public static function get_units(): array {
        return [
            self::SIZE_UNIT_KILOBYTE => get_string('sizekb'),
            self::SIZE_UNIT_MEGABYTE => get_string('sizemb'),
            self::SIZE_UNIT_GIGABYTE => get_string('sizegb'),
            self::SIZE_UNIT_TERABYTE => get_string('sizetb'),
        ];
    }

    /**
     * Converts seconds to some more user friendly string.
     *
     * @param int $bytes
     * @param null|string $emptyvalue The value to use when the duration is empty. If not specified, a "None" value is used.
     * @return string
     */
    public static function get_size_text(int $bytes, ?string $emptyvalue = null): string {
        if (empty($bytes)) {
            if ($emptyvalue !== null) {
                return $emptyvalue;
            }
            return get_string('none');
        }
        $data = self::parse_size($bytes);
        return match ($data['u']) {
            self::SIZE_UNIT_MEGABYTE => "{$data['v']} " . get_string('sizemb'),
            self::SIZE_UNIT_GIGABYTE => "{$data['v']} " . get_string('sizegb'),
            self::SIZE_UNIT_TERABYTE => "{$data['v']} " . get_string('sizetb'),
            default => "{$data['v']} " . get_string('sizekb'),
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
            $defaultinfo = self::get_size_text($default);
        } else if (is_array($default)) {
            $defaultinfo = self::get_size_text($default['v'] * $default['u']);
        } else {
            $defaultinfo = null;
        }

        return unit_setting_output::output_html(
            $this,
            $data,
            self::get_units(),
            $this->defaultunit,
            $defaultinfo,
            $query,
        );
    }

    /**
     * Returns current value of this setting.
     *
     * @return array|null array or NULL if not set yet.
     */
    public function get_setting(): ?array {
        $bytes = $this->config_read($this->name);
        if (is_null($bytes)) {
            return null;
        }

        return self::parse_size($bytes);
    }

    /**
     * Store the duration as seconds.
     *
     * @param array $data Must be form 'h'=>xx, 'm'=>xx
     * @return string error message or empty string on success
     */
    public function write_setting($data): string {
        if (!is_array($data)) {
            return '';
        }

        $unit = (int)$data['u'];
        $value = (int)$data['v'];
        $seconds = $value * $unit;

        $result = $this->config_write($this->name, $seconds);
        return ($result ? '' : get_string('errorsetting', 'admin'));
    }
}
