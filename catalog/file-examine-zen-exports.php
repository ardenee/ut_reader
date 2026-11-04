<?php
declare(strict_types=1);

/**
 * Zen raw source columns precede all decoded and catalogue-enriched columns.
 * No original IoStore or UEDB5 file is read in these browser helpers.
 */

/** @param list<array<string,mixed>> $rows @param array<string,string> $scriptLabels
 * @param array<string,array<string,mixed>> $scripts @param array<string,int> $localImports
 * @param array<string,array<string,mixed>> $external */
function zen_exam_exports(
    array $rows,array $scriptLabels,array $scripts,array $localImports,
    array $external,int $fileId,int $size,string $target
):void {
    echo '<h2>Exports — serialized FExportMapEntry</h2>'
        .'<p class="muted">The first twelve columns preserve the actual serialized '
        .'FExportMapEntry fields, including the two uint32 FMappedName words. '
        .'Reference links go to exact ImportMap/ExportMap indices. The last three '
        .'columns are decoded information, not extra header fields.</p>'
        .'<div class="examine-table-region"><table class="examine-exports-table examine-zen-exports" '
        .'data-catalog-identity-skip="1"><thead>'
        .'<tr><th colspan="12">Serialized ExportMap fields</th>'
        .'<th colspan="3">Decoded identities and catalogue paths</th></tr>'
        .'<tr><th>Index</th><th>CookedSerialOffset (uint64)</th>'
        .'<th>CookedSerialSize (uint64)</th><th>ObjectName (FMappedName, 2 × uint32)</th>'
        .'<th>OuterIndex (uint64)</th><th>ClassIndex (uint64)</th>'
        .'<th>SuperIndex (uint64)</th><th>TemplateIndex (uint64)</th>'
        .'<th>PublicExportHash (uint64)</th><th>ObjectFlags (uint32)</th>'
        .'<th>FilterFlags (uint8)</th><th>Padding (3 bytes)</th>'
        .'<th>Object</th><th>Class</th><th>Path (derived)</th></tr></thead><tbody>';
    foreach($rows as $row){
        $index=(int)$row['export_index'];
        $id='export-'.$index;
        $mapped=(array)($row['object_name_mapped']??[]);
        $nameIndex=isset($mapped['name_index'])?(int)$mapped['name_index']:null;
        $mappedHtml=zen_exam_hex((string)($mapped['raw_index']??''),8);
        if($nameIndex!==null){
            $mappedHtml.='<div class="examine-zen-secondary">NameIndex: <a class="xref mono" href="'
                .catalog_h(examine_href($fileId,'name-'.$nameIndex,$size))
                .'">'.$nameIndex.'</a></div>';
            $mappedHtml.='<div class="examine-zen-secondary">Type: '
                .(int)($mapped['type']??0).' · Number: '
                .(int)($mapped['number']??0).'</div>';
        }
        $object=examine_name_index_link(
            (string)($row['object_name']??''),$nameIndex,$fileId,$size
        );
        $class=examine_zen_table_reference(
            (array)($row['class_index']??[]),$scriptLabels,$localImports,$fileId,$size
        );
        $path=(string)($row['full_path']??'');
        echo '<tr id="'.$id.'"'.($target===$id?' class="is-reference-target"':'').'>'
            .'<td class="mono">'.$index.'</td>'
            .'<td>'.zen_exam_hex((string)($row['cooked_serial_offset_u64']??'')).'</td>'
            .'<td>'.zen_exam_hex((string)($row['cooked_serial_size_u64']??'')).'</td>'
            .'<td>'.$mappedHtml.'</td>';
        foreach(['outer_index','class_index','super_index','template_index'] as $field){
            $ref=(array)($row[$field]??[]);
            echo '<td>'.zen_exam_raw_ref($ref,$localImports,$scripts,$external,$fileId,$size).'</td>';
        }
        echo '<td>'.zen_exam_hex((string)($row['public_export_hash']??'')).'</td>'
            .'<td>'.zen_exam_hex((string)($row['object_flags']??''),8).'</td>'
            .'<td class="mono">'.(int)($row['filter_flags']??0).'</td>'
            .'<td>'.zen_exam_hex((string)($row['pad']??''),6).'</td>'
            .'<td>'.$object.'</td>'
            .'<td>'.$class.'</td>'
            .'<td class="mono path">'.($path!==''?catalog_h($path):'—').'</td></tr>';
    }
    echo '</tbody></table></div>';
}

/** @param array<string,mixed> $row */
function zen_exam_header_value(mixed $value):string
{
    if(is_bool($value))return $value?'true':'false';
    if($value===null)return '<span class="muted">Not serialized</span>';
    if(is_array($value)){
        return '<code class="mono path">'.catalog_h(
            json_encode($value,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)
        ).'</code>';
    }
    return '<span class="mono path">'.catalog_h((string)$value).'</span>';
}

/**
 * Exactly decoded FZenPackageSummary from existing SHA-bound UEDB5 snapshot.
 * This is the actual cooked header (not the unrelated classic package tag).
 */
function zen_exam_raw_header_html(\PDO $db,int $fileId,int $pageSize):string
{
    $header=(new \UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection($db))
        ->header($fileId);
    if($header===null){
        return '<div class="card"><h2>Raw Zen package header</h2>'
            .'<p class="muted">The serialized FZenPackageSummary has not yet been '
            .'materialized in the source-bound SQL examination index. '
            .'No classic package header is substituted.</p></div>';
    }
    $summary=(array)($header['summary']??[]);
    if($summary===[]){
        return '<div class="card"><h2>Raw Zen package header</h2>'
            .'<p class="muted">No serialized FZenPackageSummary in the verified projection.</p></div>';
    }
    $fieldTypes=[
        'has_versioning_info'=>'uint32',
        'header_size'=>'uint32',
        'name'=>'FMappedName (uint32 RawIndex, uint32 Number)',
        'package_flags'=>'uint32 flags',
        'cooked_header_size_or_reserved'=>'uint32 (version-specific)',
        'imported_public_export_hashes_offset'=>'int32',
        'import_map_offset'=>'int32',
        'export_map_offset'=>'int32',
        'export_bundle_entries_offset'=>'int32',
        'dependency_bundle_headers_offset'=>'int32',
        'dependency_bundle_entries_offset'=>'int32',
        'imported_package_names_offset'=>'int32',
    ];
    $result='<div class="card"><h2>Raw Zen package header — FZenPackageSummary</h2>'
        .'<p class="muted">These fields are serialized in the cooked package header '
        .'and were decoded once from the verified source. IoStore FPackageId, '
        .'game assignment, storage filename and scanner status are <em>not</em> '
        .'FZenPackageSummary fields.</p>'
        .'<div class="examine-table-region"><table><thead><tr><th>Serialized field</th>'
        .'<th>Value type</th><th>Recorded value</th></tr></thead><tbody>';
    foreach($fieldTypes as $field=>$type){
        if(!array_key_exists($field,$summary))continue;
        $value=$summary[$field];
        if($field==='name' && is_array($value)){
            $index=(int)($value['name_index']??-1);
            $valueHtml='RawIndex: '.zen_exam_hex((string)($value['raw_index']??''),8)
                .' · Type: '.(int)($value['type']??0)
                .' · NameIndex: ';
            $valueHtml.=$index>=0
                ? '<a class="xref mono" href="'.catalog_h(examine_href($fileId,'name-'.$index,$pageSize)).'">'.$index.'</a>'
                : '<span class="muted">Not present</span>';
            $valueHtml.=' · Number: '.(int)($value['number']??0);
        }else{
            $valueHtml=zen_exam_header_value($value);
        }
        $result.='<tr'.($field==='name'?' id="zen-raw-header-name"':'').'><th class="mono">'.catalog_h($field).'</th>'
            .'<td class="mono">'.catalog_h($type).'</td>'
            .'<td>'.$valueHtml.'</td></tr>';
    }
    $result.='</tbody></table></div>';
    $versioning=$header['versioning_info']??null;
    if(is_array($versioning) && $versioning!==[]){
        $result.='<details style="margin-top:12px"><summary><strong>Serialized versioning information</strong></summary>'
            .'<div class="examine-table-region"><table><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';
        foreach($versioning as $field=>$value){
            $result.='<tr><th class="mono">'.catalog_h((string)$field).'</th><td>'
                .zen_exam_header_value($value).'</td></tr>';
        }
        $result.='</tbody></table></div></details>';
    }else{
        $result.='<p class="muted">Optional versioning information: not serialized.</p>';
    }
    return $result.'</div>';
}
