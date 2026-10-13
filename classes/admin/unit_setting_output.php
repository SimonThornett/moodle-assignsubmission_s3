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

/**
 * Render settings with a numeric value and a unit selector.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unit_setting_output {
    /**
     * Render the core duration layout with the supplied units.
     *
     * @param admin_setting $setting Setting being rendered
     * @param array|null $data Current value and unit
     * @param array $units Available unit labels indexed by multiplier
     * @param int $defaultunit Default unit multiplier
     * @param string|null $defaultinfo Formatted default value
     * @param string $query Search query
     * @return string Setting HTML
     */
    public static function output_html(
        admin_setting $setting,
        ?array $data,
        array $units,
        int $defaultunit,
        ?string $defaultinfo,
        string $query,
    ): string {
        global $OUTPUT;

        $context = (object) [
            'id' => $setting->get_id(),
            'name' => $setting->get_full_name(),
            'value' => $data['v'] ?? '',
            'readonly' => $setting->is_readonly(),
            'options' => array_map(function (int $unit) use ($units, $data, $defaultunit): array {
                return [
                    'value' => $unit,
                    'name' => $units[$unit],
                    'selected' => isset($data) && (($data['v'] == 0 && $unit == $defaultunit) || $unit == $data['u']),
                ];
            }, array_keys($units)),
        ];
        $element = $OUTPUT->render_from_template('core_admin/setting_configduration', $context);

        return format_admin_setting(
            $setting,
            $setting->visiblename,
            $element,
            $setting->description,
            $setting->get_id() . 'v',
            '',
            $defaultinfo,
            $query,
        );
    }
}
