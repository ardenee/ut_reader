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
        'package_index' => 'Ref',
        'serialized_offset' => 'Offset',
        'name_text', 'text' => 'Name',
        'object_name' => 'Object name',
        'class_package' => 'Class',
        'class_name' => 'Type',
        'outer_index' => 'Outer',
        'class_index' => 'Class',
        'super_index' => 'Super',
        'template_index' => 'Template',
        'archetype_index' => 'Archetype',
        'outer_index_serialized' => 'Outer stored?',
        'outer_index_serialized_width_bits' => 'Outer width',
        'object_flags' => 'Flags',
        'object_flags_serialized_width_bits' => 'Flags width',
        'serial_size_encoding' => 'Size encoding',
        'serial_offset_present' => 'Offset stored?',
        'serial_offset_encoding' => 'Offset encoding',
        'serial_size' => 'Size',
        'serial_offset' => 'Data offset',
        'local_path' => 'Local path',
        'full_path' => 'Full path',
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
        'package_index' => $table === 'exports'
            ? 'Positive FPackageIndex identifying this ExportMap row: export row 0 is reference 1, row 1 is reference 2, etc. Click to highlight the original export record; this is not an external dependency.'
            : 'Negative FPackageIndex identifying this ImportMap row: import row 0 is reference -1, row 1 is reference -2, etc. Click to highlight the original import record; this is not an external dependency.',
        'serialized_offset' => 'Original byte position of the table entry in the package file (provenance).',
        'class_package' => 'FName of the package defining the imported object class; click the text to open the exact Name-table entry.',
        'class_name' => 'FName of the imported object type; click to open its original Name-table entry.',
        'object_name' => 'FName of the imported object; click to open its original Name-table entry.',
        'outer_index' => 'Original signed OuterIndex: 0 means no outer; negative values reference imports and positive values reference exports. In Exports the referenced name is displayed with the signed value on hover.',
        'outer_index_serialized_width_bits' => 'Width, in bits, of the serialized OuterIndex field. 32 means a four-byte integer.',
        'outer_index_serialized' => 'Whether the source-version ExportMap actually serialized an OuterIndex value; it is a format/version gate, not a new object index.',
        'object_flags_serialized_width_bits' => 'Bit width (normally 32 or 64) of the source ObjectFlags field.',
        'serial_size_encoding' => 'The source-version integer encoding used for SerialSize, such as UE1 CompactIndex.',
        'serial_offset_present' => 'Whether the source ExportMap serialized a SerialOffset. This depends on the engine/version and size gate.',
        'serial_offset_encoding' => 'Integer encoding used for SerialOffset, when serialized.',
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
 * @param array<int,list<array{file_id:int,export_index:int,path:string}>> $exactExportPaths
 */
/**
 * Navigation-only fallback when the exact ExportMap object is not indexed.
 * A matching physical package is useful to inspect, but is NOT a native
 * VerifyImport resolution or proof that the requested object exists.
 * @param list<int> $ids
 */
function examine_package_candidate_fallback(string $path, string $root, int $gameId, array $ids): string
{
    $plain = '<span class="mono examine-source-value">' . catalog_h($path) . '</span>';
    if ($path === '' || $root === '' || $gameId < 1 || $ids === []) return $plain;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids),
        static fn(int $id): bool => $id > 0)));
    if ($ids === []) return $plain;
    $href = count($ids) === 1
        ? 'file-info.php?id=' . $ids[0]
        : 'game-files.php?id=' . $gameId . '&file_filter=' . rawurlencode($root);
    $suffix = count($ids) > 1 ? ' (' . count($ids) . ' files)' : '';
    return '<a class="xref mono examine-package-candidate" href="' . catalog_h($href)
        . '" title="Indexed package candidate only; no exact serialized ExportMap object matched. '
        . 'This is not a selected engine mount or verified dependency">'
        . catalog_h($path) . '</a><span class="muted small" title="Package candidate, '
        . 'not a matching serialized export"> package only' . $suffix . '</span>';
}

/**
 * Original signed FPackageIndex is preserved in the tooltip. Text is read
 * from the pointed-to ImportMap or ExportMap row in this same physical file.
 */
/** Compact source-format fields hidden from the wide ExportMap table. */
function examine_export_format_fields(): array
{
    return [
        'outer_index_serialized', 'outer_index_serialized_width_bits',
        'object_flags_serialized_width_bits', 'serial_size_encoding',
        'serial_offset_present', 'serial_offset_encoding',
        'serial_range_serialized_width_bits',
    ];
}

/** Lossless row-level disclosure of original format/version serialization fields. */
function examine_export_format_disclosure(array $source): string
{
    $parts = [];
    foreach (examine_export_format_fields() as $field) {
        if (!array_key_exists($field, $source)) continue;
        $value = $source[$field];
        if (is_bool($value)) {
            $display = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $display = 'null';
        } elseif (is_scalar($value)) {
            $display = (string)$value;
        } else {
            $display = json_encode($value,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        }
        $parts[] = '<div data-source-field="' . catalog_h($field)
            . '" title="' . catalog_h(examine_source_description($field,'exports'))
            . '"><strong>' . catalog_h($field) . ':</strong> '
            . catalog_h($display) . '</div>';
    }
    if ($parts === []) return '<span class="muted">-</span>';
    return '<details class="examine-source-composite examine-export-format">'
        . '<summary class="mono">' . count($parts) . ' fields</summary>'
        . '<div class="mono">' . implode('', $parts) . '</div></details>';
}

function examine_export_reference(int $reference, string $field, array $labels, int $fileId, int $pageSize): string
{
    if ($reference === 0) {
        return '<span class="muted" title="Original '.catalog_h($field)
            .' signed reference: 0 (null)">-</span>';
    }
    $target = $reference < 0 ? 'import-' . (-$reference - 1)
        : 'export-' . ($reference - 1);
    $name = (string)($labels[$field] ?? '');
    $display = $name !== '' ? $name : (string)$reference;
    $title = 'Original '. $field . ' signed reference: '. $reference
        . '; points to ' . ($reference < 0 ? 'ImportMap' : 'ExportMap')
        . ' index ' . (abs($reference) - 1);
    if ($name === '') $title .= '; referenced name unavailable';
    return '<a class="xref mono examine-export-reference" href="'
        . catalog_h(examine_href($fileId, $target, $pageSize))
        . '" title="' . catalog_h($title) . '" data-original-reference="'
        . $reference . '">' . catalog_h($display) . '</a>';
}

/**
 * An export path can be used to *search* for importers. Search hits are not
 * proven reverse engine dependencies: source mount and VerifyImport are separate.
 */
function examine_export_import_search(string $path, int $gameId, ?string $lookupPath = null): string
{
    if ($path === '' || $gameId < 1) {
        return '<span class="mono examine-source-value">' . catalog_h($path) . '</span>';
    }
    $lookupPath = $lookupPath !== null && $lookupPath !== '' ? $lookupPath : $path;
    return '<a class="xref mono examine-export-import-search" href="'
        . catalog_h('index.php?' . http_build_query([
            'page'=>'search', 'q'=>$lookupPath, 'game_id'=>$gameId, 'scope'=>['imports']
        ]))
        . '" title="Search same-game indexed ImportMaps for this path. '
        . 'Search results are not verified reverse dependencies">'
        . catalog_h($path) . '</a>';
}

function examine_classic_full_source_table(
    string $table,
    array $rows,
    array $file,
    int $fileId,
    int $pageSize,
    string $target,
    bool $dependencyCurrent,
    array $packageFiles,
    array $exactExportPaths = []
): void {
    if (!in_array($table, ['names','imports','exports'], true)) {
        throw new RuntimeException('Invalid source table.');
    }
    $sourceKeys = examine_source_columns($rows);
    $derived = match ($table) {
        'imports' => ['root_package' => 'Root (derived)',
            'outer_name' => 'Outer',
            'full_path' => 'Full path (derived)'],
        'exports' => ['local_path' => 'Local path',
            'full_path' => 'Full path'],
        default => [],
    };
    $engine = strtoupper((string)($file['profile_engine'] ?? $file['engine_key'] ?? ''));
    $label = $engine !== '' ? $engine : 'Classic Unreal';
    // Keep every indexed source column. In Imports the derived Root and Full
    // Path are placed directly around Object name for readable object identity.
    $columns = [];
    $placedDerived = [];
    foreach ($sourceKeys as $key) {
        if ($table === 'exports'
            && in_array($key, examine_export_format_fields(), true)) {
            // These are important source metadata, available on the related
            // value's tooltip rather than seven repetitive table columns.
            continue;
        }
        if ($table === 'imports' && $key === 'outer_index_serialized_width_bits'
            && in_array('outer_index',$sourceKeys,true)) {
            // Keep the original serialized width visible beside its Outer ref,
            // rather than using a wide dedicated column just to show 32.
            continue;
        }
        if ($table === 'imports' && $key === 'object_name') {
            $columns[] = ['kind'=>'derived','key'=>'root_package'];
            $columns[] = ['kind'=>'derived','key'=>'outer_name'];
            $placedDerived['root_package'] = true;
            $placedDerived['outer_name'] = true;
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
    if ($table === 'exports'
        && array_intersect(examine_export_format_fields(), $sourceKeys) !== []) {
        $columns[] = ['kind'=>'format','key'=>'__source_format'];
    }
    echo '<h2>' . catalog_h(ucfirst($table)) . ' — '
        . catalog_h($label) . ' source fields</h2>'
        . '<p class="muted">Every indexed source field is retained. '
        . 'FName cells show the original text; hover for its name index and '
        . 'number, or click to open the Name table. '
        . 'Root, Outer and Full path are reconstructed by following serialized '
        . 'OuterIndex references; they are not separate serialized fields. '
        . ($table === 'exports'
            ? 'Class, Super and Outer display pointed-to object names; '
              . 'hover for the exact original signed reference. Flags, serial '
              . 'size and data offset retain original encoding details on hover. '
              . 'Local/Full path links search for potential importers, not '
              . 'certified reverse dependencies. '
            : 'Outer ref displays the original signed number, with its field width '
              . 'available on hover. Ref identifies this import row, not an external file. ')
        . ($table === 'imports'
            ? 'Gold means a selected reference; muted orange means a missing '
              . 'catalogue package candidate. A linked Full path has an exact '
              . 'source ExportMap path candidate, not a certified native provider. '
              . 'Package-only links show indexed files without claiming an object export or loader match. '
            : '')
        . 'Scroll the table horizontally for more fields.</p>'
        . '<div class="examine-table-region examine-source-scroll" role="region" '
        . 'aria-label="' . catalog_h(ucfirst($table)) . ' full source columns" tabindex="0">'
        . '<table class="examine-source-table" data-catalog-identity-skip="1">'
        . '<thead><tr>';
    foreach ($columns as $column) {
        $key = $column['key'];
        $isDerived = $column['kind'] === 'derived';
        $heading = $column['kind'] === 'format' ? 'Format'
            : ($isDerived ? $derived[$key] : examine_source_label($key));
        $tooltip = $column['kind'] === 'format'
            ? 'Original version-gated serialization fields, preserved per row.'
            : ($isDerived
                ? ('Derived from the original Outer reference chain: ' . $key
                    . '. Not an additional serialized field.')
                : examine_source_description($key, $table));
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
            if ($column['kind'] === 'format') {
                $value = examine_export_format_disclosure($raw);
            } elseif ($column['kind'] === 'source') {
                $present = array_key_exists($key, $raw);
                if ($table === 'imports' && $key === 'package_index' && $present) {
                    // Ref is a signed *local ImportMap index*, not a file or
                    // dependency provider. Make it navigable to its own exact
                    // row without inventing an external package target.
                    $ref = (int)$raw[$key];
                    $value = '<a class="xref mono examine-import-self-ref" href="'
                        . catalog_h(examine_href($fileId, $rowId, $pageSize))
                        . '" title="Ref ' . $ref
                        . ' identifies this ImportMap row ' . $index
                        . '; this is not an external file link">'
                        . $ref . '</a>';
                } elseif ($table === 'exports' && $key === 'package_index' && $present) {
                    // A positive FPackageIndex is this ExportMap entry's own identity.
                    // The source's Index field is zero-based; the positive reference is +1.
                    $ref = (int)$raw[$key];
                    $value = '<a class="xref mono examine-export-self-ref" href="'
                        . catalog_h(examine_href($fileId, $rowId, $pageSize))
                        . '" title="Export Ref ' . $ref
                        . ' identifies ExportMap row ' . $index
                        . ' in this file; click to highlight the referenced export, '
                        . 'not an external dependency">' . $ref . '</a>';
                } elseif ($table === 'imports' && $key === 'outer_index' && $present) {
                    // Display the signed serialized reference byte-for-byte as
                    // a number. Field width must *only* appear in tooltip/data,
                    // never concatenated to the visible reference.
                    $ref = (int)$raw[$key];
                    $bits = isset($raw['outer_index_serialized_width_bits'])
                        ? (int)$raw['outer_index_serialized_width_bits'] : null;
                    $description = 'Original OuterIndex: ' . $ref
                        . ($bits !== null ? '; serialized width: ' . $bits . ' bits' : '')
                        . ($ref < 0 ? '; opens Import row ' . (-$ref - 1)
                            : ($ref > 0 ? '; opens Export row ' . ($ref - 1)
                                : '; null parent (no reference)'));
                    $extra = ' title="' . catalog_h($description) . '"'
                        . ($bits !== null ? ' data-outer-width-bits="' . $bits . '"' : '');
                    if ($ref === 0) {
                        $value = '<span class="mono examine-source-outer-ref"'
                            . $extra . '>0</span>';
                    } else {
                        $outerTarget = $ref < 0 ? 'import-' . ((-$ref) - 1)
                            : 'export-' . ($ref - 1);
                        $value = '<a class="xref mono examine-source-outer-ref" href="'
                            . catalog_h(examine_href($fileId, $outerTarget, $pageSize))
                            . '"' . $extra . '>' . $ref . '</a>';
                    }
                } elseif ($table === 'exports' && $present
                    && in_array($key,['class_index','super_index','outer_index',
                        'template_index','archetype_index'],true)
                    && is_int($raw[$key])) {
                    $value = examine_export_reference(
                        (int)$raw[$key],$key,(array)($row['__reference_labels']??[]),
                        $fileId,$pageSize
                    );
                    if ($key === 'outer_index') {
                        $value = str_replace(' title="', ' title="'.catalog_h(
                            'OuterIndex serialized: '.(array_key_exists('outer_index_serialized',$raw)
                                ? (!empty($raw['outer_index_serialized'])?'yes':'no'):'not separately recorded')
                            .'; width: '.($raw['outer_index_serialized_width_bits']??'n/a')
                            .' bits; '
                        ), $value);
                    }
                } elseif ($table === 'exports' && $present
                    && in_array($key,['object_flags','serial_size','serial_offset'],true)) {
                    $extra = match($key) {
                        'object_flags' => 'Serialized width: '.($raw['object_flags_serialized_width_bits']??'unknown').' bits',
                        'serial_size' => 'Encoding: '.($raw['serial_size_encoding']??'version-dependent'),
                        default => 'Present: '.(array_key_exists('serial_offset_present',$raw)
                            ? (!empty($raw['serial_offset_present'])?'yes':'no'):'version-dependent')
                            .'; encoding: '.($raw['serial_offset_encoding']??'version-dependent')
                    };
                    $value = '<span class="mono examine-source-value" title="'
                        .catalog_h($extra).'">'.catalog_h((string)$raw[$key]).'</span>';
                } else {
                    $value = $present ? examine_source_value(
                        $raw[$key], $fileId, $pageSize, $table, $key
                    ) : '<span class="muted" title="Not serialized in this source version">—</span>';
                }
            } elseif ($table === 'exports' && in_array($key,['local_path','full_path'],true)) {
                $value = examine_export_import_search(
                    (string)($row[$key]??''), (int)$file['game_id'],
                    (string)($row['full_path']??'')
                );
            } elseif ($key === 'root_package') {
                $root = (string)($row[$key] ?? '');
                $value = $root !== '' ? PdoPackageTablePageQuery::packageReferenceHtml(
                    $root, (int)$file['game_id'], $packageFiles[strtolower($root)] ?? []
                ) : '<span class="muted">Unknown</span>';
            } elseif ($key === 'outer_name') {
                $ref = (int)($row['outer_index'] ?? 0);
                $outer = trim((string)($row['outer_name'] ?? ''));
                if ($ref === 0) {
                    $value = '<span class="muted" title="No parent: OuterIndex is zero">—</span>';
                } elseif ($outer === '') {
                    $value = '<span class="muted" title="Parent identity is not resolved">Unknown</span>';
                } else {
                    $outerTarget = $ref < 0 ? 'import-' . ((-$ref) - 1)
                        : 'export-' . ($ref - 1);
                    $value = '<a class="xref mono examine-outer-name" href="'
                        . catalog_h(examine_href($fileId,$outerTarget,$pageSize))
                        . '" title="Outer ref ' . $ref . ': open the referenced '
                        . ($ref < 0 ? 'Import' : 'Export')
                        . ' row">' . catalog_h($outer) . '</a>';
                }
            } elseif ($table === 'imports' && $key === 'full_path') {
                $path = (string)($row[$key] ?? '');
                $targets = $exactExportPaths[$index] ?? [];
                if ($path === '' || $targets === []) {
                    $root = trim((string)($row['root_package'] ?? ''));
                    $value = examine_package_candidate_fallback(
                        $path, $root, (int)$file['game_id'],
                        $packageFiles[strtolower($root)] ?? []
                    );
                } elseif (count($targets) === 1) {
                    $match = $targets[0];
                    $value = '<a class="xref mono examine-matched-export" href="'
                        . catalog_h(examine_href(
                            (int)$match['file_id'],
                            'export-' . (int)$match['export_index'], $pageSize
                        ))
                        . '" title="Exact indexed ExportMap path in file #'
                        . (int)$match['file_id'] . ', Export #'
                        . (int)$match['export_index']
                        . '. Catalogue match only; runtime mount/VerifyImport not proven">'
                        . catalog_h($path) . '</a>';
                } else {
                    // Multiple physical providers (or more than one identical
                    // export) are real ambiguity, not licence to choose one.
                    $value = '<details class="examine-export-options">'
                        . '<summary class="mono" title="Multiple exact source '
                        . 'ExportMap paths; choose a physical file">'
                        . catalog_h($path) . ' <span class="muted">('
                        . count($targets) . ' exports)</span></summary>';
                    foreach ($targets as $match) {
                        $value .= '<a class="xref mono examine-matched-export" href="'
                            . catalog_h(examine_href(
                                (int)$match['file_id'],
                                'export-' . (int)$match['export_index'], $pageSize
                            ))
                            . '" title="Exact indexed path only; not a native provider selection">'
                            . 'File #' . (int)$match['file_id']
                            . ' / Export #' . (int)$match['export_index'] . '</a>';
                    }
                    $value .= '</details>';
                }
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
