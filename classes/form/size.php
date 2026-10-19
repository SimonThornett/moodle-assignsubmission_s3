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

namespace assignsubmission_s3\form;

use assignsubmission_s3\admin\admin_setting_config_size;
use HTML_QuickForm_Renderer;
use HTML_QuickForm_Renderer_Default;
use MoodleQuickForm;
use MoodleQuickForm_group;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/form/group.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/form/text.php');

/**
 * Form element for file sizes with number and unit dropdown.
 *
 * @package   assignsubmission_s3
 * @author    Simon Thornett <simon.thornett@catalyst-eu.net>
 * @copyright Catalyst IT, 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class size extends MoodleQuickForm_group {
    /** @var int Default unit for the size element. */
    protected int $defaultunit = admin_setting_config_size::DEFAULT_MAXBYTESUNIT;

    /**
     * Constructor for the size form element.
     *
     * @param ?string $elementname Element name.
     * @param mixed $elementlabel Element label.
     * @param mixed $options Options or attributes array.
     * @param mixed $attributes HTML attributes for the element.
     */
    public function __construct(
        ?string $elementname = null,
        mixed $elementlabel = null,
        mixed $options = [],
        mixed $attributes = null,
    ) {
        $actualattributes = null;
        $actualoptions = [];
        if (is_array($options)) {
            if (isset($options['defaultunit'])) {
                $actualoptions = $options;
                $actualattributes = $attributes;
            } else {
                $actualattributes = $options;
            }
        } else {
            $actualattributes = $attributes ?: $options;
        }

        parent::__construct($elementname, $elementlabel, null, null, true, $actualattributes);
        $this->_persistantFreeze = true;
        $this->_appendName = true;
        $this->_type = 'group';

        if (isset($actualoptions['defaultunit'])) {
            $this->defaultunit = (int) $actualoptions['defaultunit'];
        }
    }

    /**
     * Register this element type with MoodleQuickForm.
     *
     * @return void
     */
    public static function register(): void {
        global $CFG;

        $filepath = $CFG->dirroot . '/mod/assign/submission/s3/classes/form/size.php';
        MoodleQuickForm::registerElementType('assignsubmission_s3_size', $filepath, self::class);
        MoodleQuickForm::registerElementType('size', $filepath, self::class);
    }

    /**
     * Create the child number and unit elements for this size element.
     *
     * @return void
     */
    public function create_elements(): void {
        $attributes = $this->getAttributesForFormElement();
        if (!isset($attributes['size'])) {
            $attributes['size'] = 5;
        }
        $this->_elements = [];
        $number = $this->createFormElement(
            'text',
            'number',
            get_string('size', 'assignsubmission_s3'),
            $attributes,
            true,
        );
        $number->set_force_ltr(true);
        $this->_elements[] = $number;
        unset($attributes['size']);
        $this->_elements[] = $this->createFormElement(
            'select',
            'unit',
            get_string('sizeunit', 'assignsubmission_s3'),
            admin_setting_config_size::get_units(),
            $attributes,
            true,
        );
        foreach ($this->_elements as $element) {
            if (method_exists($element, 'setHiddenLabel')) {
                $element->setHiddenLabel(true);
            }
        }
    }

    /**
     * Resolve the value to display in child elements.
     *
     * @param mixed $value Raw value.
     * @return array
     */
    protected function resolve_element_value(mixed $value): array {
        if (!is_array($value)) {
            $parsed = admin_setting_config_size::parse_size((int) $value);
            return ['number' => $parsed['v'], 'unit' => $parsed['u']];
        }
        if (isset($value['v']) || isset($value['u'])) {
            return [
                'number' => $value['number'] ?? $value['v'] ?? '',
                'unit' => $value['unit'] ?? $value['u'] ?? $this->defaultunit,
            ];
        }
        return $value;
    }

    /**
     * Called by HTML_QuickForm whenever a form event is made on this element.
     *
     * @param string $event Name of event.
     * @param mixed $arg Event arguments.
     * @param MoodleQuickForm $caller Calling form object.
     * @return ?bool
     */
    public function onquickformevent($event, $arg, &$caller) {
        $this->setMoodleForm($caller);
        switch ($event) {
            case 'updateValue':
                $value = $this->_findValue($caller->_constantValues);
                if ($value === null) {
                    $submitvalue = $this->_findValue($caller->_submitValues);
                    $value = ($caller->isSubmitted() && !$caller->is_new_repeat($this->getName())) ?
                        $submitvalue : $this->_findValue($caller->_defaultValues);
                }
                $this->setValue($this->resolve_element_value($value));
                break;

            case 'createElement':
                $this->create_elements();
                $caller->setType($arg[0] . '[number]', PARAM_INT);
                $caller->setType($arg[0] . '[unit]', PARAM_INT);
                return parent::onQuickFormEvent($event, $arg, $caller);

            default:
                return parent::onQuickFormEvent($event, $arg, $caller);
        }
        return true;
    }

    /**
     * Validate the submitted size value.
     *
     * @param array $values Submitted values.
     * @return ?string Error string if invalid, null otherwise.
     */
    #[\Override]
    public function validatesubmitvalue($values): ?string {
        if ($this->has_enabled_checkbox() && empty($this->_mform->getSubmitValue('assignsubmission_s3_enabled'))) {
            return null;
        }

        $bytes = $this->exportvalue($values);
        if ($bytes === null || $bytes <= 0) {
            return get_string('err_positiveint', 'core_form');
        }
        return null;
    }

    /**
     * Check for the plugin checkbox, including the assignment's submission types group.
     *
     * @return bool Whether the form contains the plugin checkbox.
     */
    private function has_enabled_checkbox(): bool {
        if (!$this->_mform) {
            return false;
        }
        $enabledname = 'assignsubmission_s3_enabled';
        if ($this->_mform->elementExists($enabledname)) {
            return true;
        }
        if ($this->_mform->elementExists('submissionplugins')) {
            foreach ($this->_mform->getElement('submissionplugins')->getElements() as $element) {
                if ($element->getName() === $enabledname) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Render HTML for the size element.
     *
     * @return string
     */
    public function tohtml(): string {
        include_once('HTML/QuickForm/Renderer/Default.php');
        $renderer = new HTML_QuickForm_Renderer_Default();
        $renderer->setElementTemplate('{element}');
        parent::accept($renderer);
        return $renderer->toHtml();
    }

    /**
     * Accept a renderer to render the element.
     *
     * @param HTML_QuickForm_Renderer $renderer QuickForm renderer.
     * @param bool $required Whether the element is required.
     * @param ?string $error Error message associated with the element.
     * @return void
     */
    public function accept(&$renderer, $required = false, $error = null) {
        $renderer->renderElement($this, $required, $error);
    }

    /**
     * Output the size as total bytes integer.
     *
     * @param array $submitvalues Submitted values.
     * @param bool $assoc Whether to return as associative array.
     * @return mixed Total bytes as integer or null.
     */
    public function exportvalue(&$submitvalues, $assoc = false): mixed {
        $valuearray = [];
        foreach ($this->_elements as $element) {
            $thisexport = $element->exportValue($submitvalues[$this->getName()], true);
            if ($thisexport !== null) {
                $valuearray += $thisexport;
            }
        }

        if (empty($valuearray)) {
            return null;
        }

        $number = (float) ($valuearray['number'] ?? $valuearray['v'] ?? 0);
        $unit = (int) ($valuearray['unit'] ?? $valuearray['u'] ?? $this->defaultunit);

        return $this->_prepareValue((int) round($number * $unit), $assoc);
    }
}
