<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the presentation class `SelectField` for select field.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Presentation-layer code for HTTP/UI rendering and reusable interface components.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use InvalidArgumentException;
use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class SelectField
{
    /**
     * @param array{id:string,name:string,label:string,value?:string,options:array<string,string>,help?:string,error?:string,class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(array $props): string
    {
        $id = trim((string)($props['id'] ?? ''));
        $name = trim((string)($props['name'] ?? ''));
        $label = trim((string)($props['label'] ?? ''));
        $options = is_array($props['options'] ?? null) ? $props['options'] : [];
        if ($id === '' || $name === '' || $label === '') {
            throw new InvalidArgumentException('SelectField requires id, name, and label.');
        }

        $help = trim((string)($props['help'] ?? ''));
        $error = trim((string)($props['error'] ?? ''));
        $helpId = $help !== '' ? $id . '-help' : '';
        $errorId = $error !== '' ? $id . '-error' : '';
        $describedBy = array_values(array_filter([$helpId, $errorId]));
        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        if ($describedBy !== []) {
            $attributes['aria-describedby'] = implode(' ', $describedBy);
        }
        if ($error !== '') {
            $attributes['aria-invalid'] = 'true';
        }

        $value = (string)($props['value'] ?? '');
        $wrapperClass = Html::classes('ui-field', 'ui-field--select', $error !== '' ? 'ui-field--error' : '', (string)($props['class'] ?? ''));
        $html = '<label class="' . Html::escape($wrapperClass) . '" for="' . Html::escape($id) . '">';
        $html .= '<span class="ui-field__label">' . Html::escape($label) . '</span>';
        $html .= '<select class="ui-select" id="' . Html::escape($id) . '" name="' . Html::escape($name) . '"' . Html::attributes($attributes) . '>';
        foreach ($options as $optionValue => $optionLabel) {
            $html .= '<option value="' . Html::escape($optionValue) . '"' . ((string)$optionValue === $value ? ' selected' : '') . '>'
                . Html::escape($optionLabel) . '</option>';
        }
        $html .= '</select>';
        if ($help !== '') {
            $html .= '<span class="ui-field__help" id="' . Html::escape($helpId) . '">' . Html::escape($help) . '</span>';
        }
        if ($error !== '') {
            $html .= '<span class="ui-field__error" id="' . Html::escape($errorId) . '" role="alert">' . Html::escape($error) . '</span>';
        }

        return $html . '</label>';
    }
}
