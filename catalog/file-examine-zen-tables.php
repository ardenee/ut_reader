<?php
declare(strict_types=1);

/**
 * UE5 Zen-only serialized table presentation.
 * Left columns are the exact persisted cooked source words. Derived paths,
 * decoded identities, and catalogue matches are exclusively right-hand data.
 * The classic UE1-UE5 serializer table renderer remains untouched.
 */

/** @param array<string,mixed> $ref @param array<string,int> $importRefs @param array<string,array<string,mixed>> $scripts @param array<string,array<string,mixed>> $external */
function zen_exam_raw_ref(array $ref,array $importRefs,array $scripts,array $external,int $fileId,int $size):string
{
    $raw=strtoupper((string)($ref['raw_type_and_id']??''));
    if(preg_match('/^[0-9A-F]{16}$/D',$raw)!==1)return '<span class="muted">Not serialized</span>';
    $label='0x'.catalog_h($raw);
    if((string)($ref['type']??'')==='Null')return '<span class="mono">'.$label.'</span>';
    $target=null;$provider=$fileId;
    if((string)($ref['type']??'')==='Export' && isset($ref['local_export_index'])){
        $target='export-'.(int)$ref['local_export_index'];
    }elseif(isset($importRefs[$raw])){
        $target='import-'.(int)$importRefs[$raw];
    }
    if($target!==null)return '<a class="xref mono" href="'
        .catalog_h(examine_href($provider,$target,$size)).'">'.$label.'</a>';
    $script=$scripts[$raw]??null;
    if(is_array($script) && (int)($script['container_id']??0)>0){
        return '<a class="xref mono" title="Exact cooked ScriptObjects identity" href="iostore-info.php?id='
            .(int)$script['container_id'].'">'.$label.'</a>';
    }
    $match=$external[$raw]??[];
    if((string)($match['state']??'')==='resolved_export'){
        return '<a class="xref mono" title="Exact indexed provider export" href="'
            .catalog_h(examine_href((int)$match['file_id'],'export-'.(int)$match['export_index'],$size))
            .'">'.$label.'</a>';
    }
    return '<span class="mono">'.$label.'</span>';
}

function zen_exam_hex(string $raw,int $maxDigits=16):string
{
    $text=strtoupper(trim($raw));
    if($text==='')return '<span class="muted">—</span>';
    return '<span class="mono">0x'.catalog_h($text).'</span>';
}

/** @param list<array<string,mixed>> $rows @param array<int,array{count:int,indices:list<int>}> $reverse */
function zen_exam_names(array $rows,array $reverse,?int $headerNameIndex,int $fileId,int $size,string $target):void
{
    echo '<h2>Names — serialized Zen NameMap</h2>'
        .'<p class="muted">The hash-version word belongs to the NameMap batch. '
        .'Only an ExportMap FMappedName containing a real local name index creates a '
        .'NameMap reference; the ImportMap has no direct local NameMap indices.</p>'
        .'<div class="examine-table-region"><table class="examine-zen-names" data-catalog-identity-skip="1">'
        .'<thead><tr><th colspan="7">Serialized NameMap data</th>'
        .'<th>Cross-references (derived)</th></tr>'
        .'<tr><th>Index</th><th>HashVersion (uint64)</th><th>NameHash (uint64)</th>'
        .'<th>IsUTF16 (bool)</th><th>Length (uint15)</th><th>NameBytes (hex)</th>'
        .'<th>Decoded text</th><th>ExportMap.ObjectName</th></tr></thead><tbody>';
    foreach($rows as $row){
        $index=(int)$row['name_index'];
        $id='name-'.$index;
        $related=$reverse[$index]??['count'=>0,'indices'=>[]];
        $links=[];
        foreach($related['indices'] as $exportIndex){
            $links[]='<a class="xref mono" href="'
                .catalog_h(examine_href($fileId,'export-'.(int)$exportIndex,$size))
                .'">'.(int)$exportIndex.'</a>';
        }
        if($headerNameIndex===$index){
            $links[]='<a class="xref" href="#zen-raw-header-name">FZenPackageSummary.Name</a>';
        }
        $linked=$links!==[]?implode(', ',$links):'<span class="muted">No serialized table references</span>';
        $remaining=(int)$related['count']-count($links);
        if($remaining>0){
            $all='file-examine.php?'.http_build_query([
                'id'=>$fileId,'tab'=>'exports','page_size'=>$size,'name_ref'=>$index
            ]).'#package-tables';
            $linked.=' · <a class="xref" href="'.catalog_h($all).'">Show all '
                .(int)$related['count'].' ExportMap references</a>';
        }
        echo '<tr id="'.$id.'"'.($target===$id?' class="is-reference-target"':'').'>'
            .'<td class="mono">'.$index.'</td>'
            .'<td>'.zen_exam_hex((string)($row['hash_version']??'')).'</td>'
            .'<td>'.zen_exam_hex((string)($row['hash']??'')).'</td>'
            .'<td class="mono">'.(!empty($row['is_utf16'])?'1 (UTF-16LE)':'0 (8-bit)').'</td>'
            .'<td class="mono">'.(int)($row['length']??0).'</td>'
            .'<td class="mono path"><code>'.catalog_h((string)($row['raw']??'')).'</code></td>'
            .'<td class="mono path">'.catalog_h((string)($row['name_text']??'')).'</td>'
            .'<td>'.$linked.'</td></tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * @param list<array<string,mixed>> $rows
 * @param array<int,array<string,mixed>> $matches
 * @param array<string,array<string,mixed>> $scripts
 */
function zen_exam_imports(array $rows,array $matches,array $scripts,int $fileId,int $size,string $target):void
{
    echo '<h2>Imports — serialized FPackageObjectIndex</h2>'
        .'<p class="muted">Every ImportMap row contains one serialized uint64 '
        .'FPackageObjectIndex (2-bit TypeTag and 62-bit Value). The indexed '
        .'provider, cooked script name, class and outer relationships on the right '
        .'are <strong>decoded or catalogue-derived</strong>.</p>'
        .'<div class="examine-table-region"><table class="examine-imports-table examine-zen-imports" data-catalog-identity-skip="1">'
        .'<thead><tr><th colspan="5">Serialized ImportMap fields</th>'
        .'<th colspan="4">Decoded identities and catalogue references</th></tr>'
        .'<tr><th>Index</th><th>FPackageObjectIndex (uint64)</th><th>TypeTag (uint2)</th>'
        .'<th>Value (uint62)</th><th>Type</th><th>Decoded indices / identity</th>'
        .'<th>Catalogue match</th><th>Object / class</th><th>Object path / outer</th>'
        .'</tr></thead><tbody>';
    $local=[];
    foreach($rows as $row){
        $raw=strtoupper((string)($row['raw_type_and_id']??''));
        $local[$raw]=(int)($row['import_index']??-1);
    }
    foreach($rows as $row){
        $index=(int)$row['import_index'];
        $id='import-'.$index;
        $kind=(string)($row['type']??'');
        $raw=strtoupper((string)($row['raw_type_and_id']??''));
        $match=$matches[$index]??[];
        $detail=$scripts[$raw]??[];
        $decoded=[];
        if($kind==='ScriptImport'){
            $decoded[]='ScriptImportHash: '.zen_exam_hex((string)($row['script_import_hash']??''));
        }elseif($kind==='PackageImport'){
            $packageId=strtoupper((string)($row['provider_package_id']??''));
            $sourceUrl='file-examine.php?'.http_build_query([
                'id'=>$fileId,'tab'=>'uses','page_size'=>$size
            ]).'#package-'.$packageId;
            $decoded[]='ImportedPackageIndex: <a class="xref mono" title="Follow the referenced FPackageId in Uses" href="'
                .catalog_h($sourceUrl).'">'.(int)($row['imported_package_index']??0).'</a>';
            $decoded[]='ImportedPublicExportHashIndex: <span class="mono">'.(int)($row['imported_public_export_hash_index']??0).'</span>';
            $decoded[]='FPackageId: <a class="xref mono" href="'
                .catalog_h($sourceUrl).'">'.zen_exam_hex((string)($row['provider_package_id']??'')).'</a>';
            $decoded[]='PublicExportHash: '.zen_exam_hex((string)($row['provider_public_export_hash']??''));
        }elseif($kind==='Export' && isset($row['local_export_index'])){
            $decoded[]='LocalExportIndex: <a class="xref mono" href="'
                .catalog_h(examine_href($fileId,'export-'.(int)$row['local_export_index'],$size))
                .'">'.(int)$row['local_export_index'].'</a>';
        }
        $status='<span class="muted">No indexed match</span>';
        $state=(string)($match['state']??'');
        if($kind==='Null')$status='<span class="muted">Null slot (no dependency)</span>';
        elseif($kind==='Export')$status='<span class="muted">Local export</span>';
        elseif($state==='resolved_script'){
            $container=(int)($match['container_id']??0);
            $status=$container>0
                ? '<a class="xref" href="iostore-info.php?id='.$container.'">ScriptObjects</a>'
                : '<span>ScriptObjects</span>';
        }elseif($state==='resolved_export'){
            $status='<a class="xref mono path" href="'
                .catalog_h(examine_href((int)$match['file_id'],'export-'.(int)$match['export_index'],$size))
                .'">'.catalog_h((string)$match['package_name']).'</a>';
        }elseif($state==='multiple_matches'){
            $status='<span class="muted">'.(int)$match['match_count'].' matching providers (ambiguous)</span>';
        }
        $identity='<span class="muted">—</span>';
        $path='<span class="muted">—</span>';
        if($kind==='ScriptImport' && is_array($detail) && $detail!==[]){
            $identity='<span class="mono path">'.catalog_h((string)$detail['name']).'</span>';
            $class=strtoupper((string)($detail['cdo_class_index']??''));
            if((string)($detail['cdo_class_path']??'')!==''){
                $classText=catalog_h((string)$detail['cdo_class_path']);
                $identity.='<div class="examine-zen-secondary">Class: '
                    .(isset($local[$class])
                        ? '<a class="xref mono path" href="'.catalog_h(examine_href($fileId,'import-'.$local[$class],$size)).'">'.$classText.'</a>'
                        : '<span class="mono path">'.$classText.'</span>').'</div>';
            }
            $outer=strtoupper((string)($detail['outer_index']??''));
            $p=catalog_h((string)($detail['path']??''));
            $container=(int)($detail['container_id']??0);
            $path=$container>0
                ? '<a class="xref mono path" href="iostore-info.php?id='.$container.'">'.$p.'</a>'
                : '<span class="mono path">'.$p.'</span>';
            if((string)($detail['outer_path']??'')!==''){
                $label=catalog_h((string)$detail['outer_path']);
                $outerLink=isset($local[$outer])
                    ? '<a class="xref mono path" href="'.catalog_h(examine_href($fileId,'import-'.$local[$outer],$size)).'">'.$label.'</a>'
                    : '<span class="mono path">'.$label.'</span>';
                $path.='<div class="examine-zen-secondary">Outer: '.$outerLink.'</div>';
            }
        }elseif($state==='resolved_export'){
            $path='<a class="xref mono path" href="'
                .catalog_h(examine_href((int)$match['file_id'],'export-'.(int)$match['export_index'],$size))
                .'">'.catalog_h((string)$match['package_name']).'</a>';
        }
        $missingSource = !in_array($kind, ['Null','Export'], true)
            && !in_array($state,
                ['resolved_script','resolved_export','multiple_matches','package_found'], true);
        $rowClass = trim(($target===$id?'is-reference-target ':'')
            . ($missingSource?'examine-import-missing':''));
        echo '<tr id="'.$id.'"'.($rowClass!==''?' class="'.$rowClass.'"':'')
            .($missingSource?' title="No exact indexed catalogue counterpart for this serialized external import"':'')
            .'>'
            .'<td class="mono">'.$index.'</td>'
            // An import's raw value should lead to its actual source match,
            // not link back to the same import row.
            .'<td>'.zen_exam_raw_ref($row,[],$scripts,[$raw=>$match],$fileId,$size).'</td>'
            .'<td class="mono">'.(int)($row['type_tag']??0).'</td>'
            .'<td>'.zen_exam_hex((string)($row['value_u62']??'')).'</td>'
            .'<td>'.catalog_h($kind).'</td>'
            .'<td class="mono">'.($decoded
                ? '<div class="examine-zen-secondary">'.implode('</div><div class="examine-zen-secondary">',$decoded).'</div>'
                : '—').'</td>'
            .'<td>'.$status.'</td>'
            .'<td>'.$identity.'</td>'
            .'<td>'.$path.'</td></tr>';
    }
    echo '</tbody></table></div>';
}
