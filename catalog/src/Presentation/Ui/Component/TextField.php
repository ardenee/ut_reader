<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Presentation\Ui\Component;

use InvalidArgumentException;
use UnrealDb\Catalog\Presentation\Ui\Support\Html;

final class TextField
{
    /**
     * @param array{id:string,name:string,label:string,value?:string,type?:string,placeholder?:string,help?:string,error?:string,class?:string,input_class?:string,attributes?:array<string,scalar|null>} $props
     */
    public static function render(array $props): string
    {
        $id = trim((string)($props['id'] ?? ''));
        $name = trim((string)($props['name'] ?? ''));
        $label = trim((string)($props['label'] ?? ''));
        if ($id === '' || $name === '' || $label === '') {
            throw new InvalidArgumentException('TextField requires id, name, and label.');
        }

        $help = trim((string)($props['help'] ?? ''));
        $error = trim((string)($props['error'] ?? ''));
        $describedBy = [];
        $helpId = $help !== '' ? $id . '-help' : '';
        $errorId = $error !== '' ? $id . '-error' : '';
        if ($helpId !== '') {
            $describedBy[] = $helpId;
        }
        if ($errorId !== '') {
            $describedBy[] = $errorId;
        }

        $attributes = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
        if ($describedBy !== []) {
            $attributes['aria-describedby'] = implode(' ', $describedBy);
        }
        if ($error !== '') {
            $attributes['aria-invalid'] = 'true';
        }

        $type = Html::enum((string)($props['type'] ?? 'text'), ['text', 'search', 'email', 'url', 'number'], 'text');
        $wrapperClass = Html::classes('ui-field', $error !== '' ? 'ui-field--error' : '', (string)($props['class'] ?? ''));
        $inputClass = Html::classes('ui-input', (string)($props['input_class'] ?? ''));
        $html = '<label class="' . Html::escape($wrapperClass) . '" for="' . Html::escape($id) . '">';
        $html .= '<span class="ui-field__label">' . Html::escape($label) . '</span>';
        $html .= '<input class="' . Html::escape($inputClass) . '" id="' . Html::escape($id) . '" name="' . Html::escape($name) . '" type="' . $type . '" value="' . Html::escape((string)($props['value'] ?? '')) . '"';
        if (trim((string)($props['placeholder'] ?? '')) !== '') {
            $html .= ' placeholder="' . Html::escape((string)$props['placeholder']) . '"';
        }
        $html .= Html::attributes($attributes) . '>';
        if ($help !== '') {
            $html .= '<span class="ui-field__help" id="' . Html::escape($helpId) . '">' . Html::escape($help) . '</span>';
        }
        if ($error !== '') {
            $html .= '<span class="ui-field__error" id="' . Html::escape($errorId) . '" role="alert">' . Html::escape($error) . '</span>';
        }

        return $html . '</label>';
    }
}
