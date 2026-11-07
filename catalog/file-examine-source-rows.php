<?php
/**
 * Lossless, version-adaptive classic package table presentation.
 *
 * UEDB5 rows retain source fields in original reader order. UE1/UE2/UE3/UE4
 * (including game/version variants) have different serialized records.
 * Do not encode one universal class/import/export structure or omit unknown
 * source fields. Derived paths are in a visually separate group.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;

/** @param list<array<string,mixed>> $rows @return list<string> */
function examine_source_columns(array $rows): array
{
    $fields = [];
    foreach ($rows as $row) {
        $original = (array)($row['__serialized_source_fields'] ?? $row);
        foreach (array_keys($original) as $field) {
            if (!isset($fields[$field])) $fields[$field] = true;
        }
    }
    return array_keys($fields);
}

function examine_source_label(string $field): string
{
    return match ($field) {
        'index' => 'Index',
        'package_index' => 'Package ref',
        'serialized_offset' => 'Byte offset',
        'name_text', 'text' => 'Name',
        'object_name' => 'Object name',
        'class_package' => 'Class package',
        'class_name' => 'Class',
        'outer_index' => 'Outer ref',
        'outer_index_serialized_width_bits' => 'Outer bits',
        'object_flags' => 'Object flags',
        'flags' => 'Name flags',
        'package_guid' => 'Package GUID',
        'serial_size' => 'Serial size',
        'serial_offset' => 'Serial offset',
        'component_map' => 'Component map',
        'generation_net_object_count' => 'Generation counts',
        'preload' => 'Preload dependencies',
        default => ucwords(str_replace('_', ' ', $field)),
    };
}

/** Human explanation and original source key for compact column headers. */
function examine_source_description(string $field, string $table): string
{
    $detail = match ($field) {
        'index' => 'Zero-based position in this ' . $table . ' table (index metadata).',
        'package_index' => 'Signed package object reference: Imports use -(row + 1), Exports use row + 1. Not an additional serialized record field.',
        'serialized_offset' => 'Original byte position of the table entry in the package file (provenance).',
        'class_package' => 'FName of the package defining the imported object class; click the text to open the exact Name-table entry.',
        'class_name' => 'FName of the imported object type; click to open its original Name-table entry.',
        'object_name' => 'FName of the imported object; click to open its original Name-table entry.',
        'outer_index' => 'Signed outer/parent object reference: zero is null; negative values refer to imports; positive values to exports.',
        'outer_index_serialized_width_bits' => 'Width, in bits, of the serialized OuterIndex field. 32 means a four-byte integer.',
        default => 'Source-record field: ' . $field,
    };
    return 'Source key: ' . $field . '. ' . $detail;
}

/**
 * Preserve every value, including 0, false, null and version-gated arrays.
 * Long arrays are available in full under a per-cell disclosure rather than
 * silently flattened into the decoded name alone.
 */
function examine_source_value(
    mixed $value,
    int $fileId,
    int $pageSize,
    string $table,
    string $field,
    int $depth = 0
): string {
    if ($value === null) return '<span class="muted" title="Explicit null in source record">null</span>';
    if (is_bool($value)) return '<span class="mono">' . ($value ? 'true' : 'false') . '</span>';
    if (is_int($value) && $depth === 0
        && $table !== 'names'
        && in_array($field, [
            'outer_index','class_index','super_index','template_index','archetype_index',
        ], true)) {
        return examine_reference($value, $fileId, $pageSize);
    }
    if (!is_array($value)) {
        $display = (string)$value;
        return '<span class="mono examine-source-value">' . catalog_h($display) . '</span>';
    }
    if ($value === []) return '<span class="mono">[]</span>';

    // Compact, source-preserving FName display. The visible cell is just the
    // resolved text (e.g. "Core"); both original words remain in title/data
    // attributes and the exact original Name-table entry is linked.
    if ($depth === 0
        && in_array($field, ['class_package','class_name','object_name'], true)
        && array_key_exists('name_index', $value)
        && array_key_exists('number', $value)
        && array_key_exists('text', $value)
        && count($value) === 3) {
        $nameIndex = (int)$value['name_index'];
        $number = (int)$value['number'];
        $display = (string)$value['text'];
        $tooltip = catalog_h('Name index: ' . $nameIndex
            . '; FName number: ' . $number);
        $attributes = ' title="' . $tooltip . '"'
            . ' data-fname-index="' . $nameIndex . '"'
            . ' data-fname-number="' . $number . '"';
        if ($nameIndex >= 0) {
            return '<a class="xref mono examine-fname"'
                . $attributes . ' href="'
                . catalog_h(examine_href($fileId, 'name-' . $nameIndex, $pageSize))
                . '">' . catalog_h($display) . '</a>';
        }
        return '<span class="mono examine-fname"' . $attributes . '>'
            . catalog_h($display) . '</span>';
    }

    // A compact FName (text, name_index and number) must retain the original
    // index/number even though the presentation reader also exposes text.
    $json = json_encode($value,
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    if (strlen($json) > 320 || count($value) > 10 || $depth > 2) {
        $pretty = json_encode($value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        return '<details class="examine-source-composite"><summary class="mono">'
            . count($value) . ' values — show entire record</summary>'
            . '<pre class="mono examine-source-expanded">' . catalog_h($pretty)
            . '</pre></details>';
    }
    $parts = [];
    foreach ($value as $key => $child) {
        $keyName = (string)$key;
        $childHtml = is_int($child) && $keyName === 'name_index'
            ? '<a class="xref mono" title="Original FName name-table index" href="'
                . catalog_h(examine_href($fileId, 'name-' . $child, $pageSize))
                . '">' . $child . '</a>'
            : examine_source_value($child, $fileId, $pageSize,
                $table, $keyName, $depth + 1);
        $parts[] = '<div class="examine-source-part"><span class="examine-source-part-label">'
            . catalog_h($keyName) . ':</span> ' . $childHtml . '</div>';
    }
    return '<div class="examine-source-parts">' . implode('', $parts) . '</div>';
}

/**
 * Render every indexed source field, followed by separately identified
 * decoded/cross-file data. There is no fixed schema that drops UE3 or UE4
 * version-gated fields.
 *
 * @param list<array<string,mixed>> $rows
 * @param array<string,list<int>> $packageFiles
 */
function examine_classic_full_source_table(
    string $table,
    array $rows,
    array $file,
    int $fileId,
    int $pageSize,
    string $target,
    bool $dependencyCurrent,
    array $packageFiles
): void {
    if (!in_array($table, ['names','imports','exports'], true)) {
        throw new RuntimeException('Invalid source table.');
    }
    $sourceKeys = examine_source_columns($rows);
    $derived = match ($table) {
        'imports' => ['root_package' => 'Root (derived)',
            'full_path' => 'Full path (derived)'],
        'exports' => ['local_path' => 'Local path (derived)',
            'full_path' => 'Full path (derived)'],
        default => [],
    };
    $engine = strtoupper((string)($file['profile_engine'] ?? $file['engine_key'] ?? ''));
    $label = $engine !== '' ? $engine : 'Classic Unreal';
    // Keep every indexed source column. In Imports the derived Root and Full
    // Path are placed directly around Object name for readable object identity.
    $columns = [];
    $placedDerived = [];
    foreach ($sourceKeys as $key) {
        if ($table === 'imports' && $key === 'object_name') {
            $columns[] = ['kind'=>'derived','key'=>'root_package'];
            $placedDerived['root_package'] = true;
        }
        $columns[] = ['kind'=>'source','key'=>$key];
        if ($table === 'imports' && $key === 'object_name') {
            $columns[] = ['kind'=>'derived','key'=>'full_path'];
            $placedDerived['full_path'] = true;
        }
    }
    foreach ($derived as $key => $_heading) {
        if (!isset($placedDerived[$key])) {
            $columns[] = ['kind'=>'derived','key'=>$key];
        }
    }
    echo '<h2>' . catalog_h(ucfirst($table)) . ' — '
        . catalog_h($label) . ' source fields</h2>'
        . '<p class="muted">Every indexed source field is retained. '
        . 'FName cells show the original text; hover for its name index and '
        . 'number, or click to open the Name table. '
        . 'Root and Full path are derived, not serialized. '
        . ($table === 'imports'
            ? 'Amber rows have no indexed package candidate. '
            : '')
        . 'Scroll the table horizontally for more fields.</p>'
        . '<div class="examine-table-region examine-source-scroll" role="region" '
        . 'aria-label="' . catalog_h(ucfirst($table)) . ' full source columns" tabindex="0">'
        . '<table class="examine-source-table" data-catalog-identity-skip="1">'
        . '<thead><tr>';
    foreach ($columns as $column) {
        $key = $column['key'];
        $isDerived = $column['kind'] === 'derived';
        $heading = $isDerived ? $derived[$key] : examine_source_label($key);
        $tooltip = $isDerived
            ? ('Derived from the original Outer reference chain: ' . $key
                . '. Not an additional serialized field.')
            : examine_source_description($key, $table);
        echo '<th title="' . catalog_h($tooltip) . '"'
            . ($isDerived ? ' class="examine-derived-col"' : '')
            . '>' . catalog_h($heading) . '</th>';
    }
    echo '</tr></thead><tbody>';


    foreach ($rows as $row) {
        $index = (int)($row[$table === 'names' ? 'name_index'
            : ($table === 'imports' ? 'import_index' : 'export_index')]
                ?? $row['index'] ?? 0);
        $rowId = rtrim($table, 's') . '-' . $index;
        $rowClass = ($target === $rowId) ? 'is-reference-target' : '';
        if ($table === 'imports' && $dependencyCurrent) {
            $root = trim((string)($row['root_package'] ?? ''));
            $scriptRoot = (int)($file['game_id'] ?? 0) === 7
                && str_starts_with(strtolower($root), '/script/');
            if ($root !== '' && !$scriptRoot && empty($packageFiles[strtolower($root)])) {
                $rowClass .= ' examine-import-missing';
            }
        }
        echo '<tr id="' . catalog_h($rowId) . '"'
            . (trim($rowClass) !== '' ? ' class="' . trim($rowClass) . '"' : '')
            . '>';
        $raw = (array)($row['__serialized_source_fields'] ?? $row);
        foreach ($columns as $column) {
            $key = $column['key'];
            if ($column['kind'] === 'source') {
                $present = array_key_exists($key, $raw);
                $value = $present ? examine_source_value(
                    $raw[$key], $fileId, $pageSize, $table, $key
                ) : '<span class="muted" title="Not serialized in this source version">—</span>';
            } elseif ($key === 'root_package') {
                $root = (string)($row[$key] ?? '');
                $value = $root !== '' ? PdoPackageTablePageQuery::packageReferenceHtml(
                    $root, (int)$file['game_id'], $packageFiles[strtolower($root)] ?? []
                ) : '<span class="muted">Unknown</span>';
            } else {
                $value = '<span class="mono examine-source-value">'
                    . catalog_h((string)($row[$key] ?? '')) . '</span>';
            }
            echo '<td class="examine-source-cell'
                . ($column['kind'] === 'derived' ? ' examine-derived-col' : '')
                . '">' . $value . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
