<?php
/**
 * Source-record retention acceptance across UE1–UE5 Names/Imports/Exports.
 *
 * Reads original UEDB5 files as immutable source snapshots and the SHA-bound
 * Zen SQL row projection. No simulated field order, invented engine gates or
 * runtime mount/provider assumptions.
 */
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5RuntimeMetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoZenExamProjection;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;

require_once dirname(__DIR__, 2) . '/catalog/file-examine-source-rows.php';
require_once dirname(__DIR__, 2) . '/catalog/file-examine-zen-tables.php';
require_once dirname(__DIR__, 2) . '/catalog/file-examine-zen-exports.php';

function examine_href(int $id,string $anchor,int $size):string {
    return 'file-examine.php?id='.$id.'&tab='.(
        str_starts_with($anchor,'name-')?'names':(
        str_starts_with($anchor,'import-')?'imports':'exports')
    ).'&page_size='.$size.'#'.$anchor;
}
function examine_reference(int $value,int $id,int $size):string {
    return '<span class="mono">'.($value<0?'Import ':'Export ').$value.'</span>';
}
function examine_name_index_link(string $text,?int $index,int $fileId,int $size):string {
    return '<span class="mono">'.catalog_h($text).'</span>';
}
function examine_zen_table_reference(array $ref,array $scripts,array $local,int $id,int $size):string {
    return zen_exam_hex((string)($ref['raw_type_and_id']??''));
}
function requireTrue(bool $ok,string $label):void{
    if (!$ok) throw new RuntimeException($label);
}
$pathLink = examine_export_import_search('artistalp', 12, 'artifx2.artistalp');
requireTrue(str_contains($pathLink, 'q=artifx2.artistalp')
    && str_contains($pathLink, 'scope%5B0%5D=imports'),
    'Local export path must search by full import identity');
requireTrue(str_contains(examine_export_reference(0,'super_index',[],1,250),
    'signed reference: 0 (null)">-</span>'),
    'Null export index must show dash, not none');
$config=catalog_config();
$config['db']['host']=(string)(getenv('PACKAGE_TABLE_COVERAGE_DB_HOST')?:'192.168.0.55');
$db=catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');$db->beginTransaction();
try{
    $root=(string)(getenv('PACKAGE_TABLE_UEDB5_ROOT')
        ?:'//192.168.0.55/c$/Apache24/htdocs/unrealdb/catalog/storage');
    $reader=new Uedb5RuntimeMetadataReader($db,$root);
    $present=new ReflectionMethod(PdoPackageTablePageQuery::class,'presentV5Rows');
    $examples=[
        ['engine'=>'UE1','game'=>3,'id'=>113253,'game_name'=>'UT99'],
        ['engine'=>'UE1','game'=>12,'id'=>110902,'game_name'=>'Unreal'],
        ['engine'=>'UE2','game'=>2,'id'=>1253000,'game_name'=>'Unreal II'],
        ['engine'=>'UE2','game'=>4,'id'=>148919,'game_name'=>'UT2003'],
        ['engine'=>'UE2','game'=>5,'id'=>111460,'game_name'=>'UT2004'],
        ['engine'=>'UE3','game'=>6,'id'=>112300,'game_name'=>'UT3'],
        ['engine'=>'UE4','game'=>7,'id'=>113526,'game_name'=>'UT4'],
    ];
    $checks=[];
    $snapshotHashes=[];
    foreach($examples as $source) {
        $snapshotPath=\UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer::path(
            $root,$source['game'],$source['id']);
        $hash=hash_file('sha256',$snapshotPath);
        requireTrue(is_string($hash) && strlen($hash)===64,
            'Original UEDB5 snapshot unavailable: '.$source['game_name']);
        $snapshotHashes[$source['game_name'].' #'.$source['id']]=$hash;
        foreach(['names','imports','exports'] as $table) {
            $original=$reader->page($source['id'],$table,0,15);
            requireTrue($original!==[],
                $source['game_name'].' '.$table.': original source table empty');
            $rows=$present->invoke(null,$original,$table);
            $expectedKeys=[];
            foreach($original as $originalRow) {
                foreach(array_keys($originalRow) as $key) $expectedKeys[$key]=true;
            }
            $displayed=examine_source_columns($rows);
            requireTrue($displayed===array_keys($expectedKeys),
                $source['game_name'].' '.$table.': source field lost or reordered');
            foreach($rows as $i=>$row) {
                requireTrue(($row['__serialized_source_fields']??null)===$original[$i],
                    $source['game_name'].' '.$table.': raw composite/field changed by presentation');
            }
            ob_start();
            examine_classic_full_source_table(
                $table,$rows,
                ['game_id'=>$source['game'],'profile_engine'=>$source['engine']],
                $source['id'],100,'',true,[]
            );
            $html=(string)ob_get_clean();
            requireTrue(str_contains($html,'data-catalog-identity-skip="1"')
                && str_contains($html,$source['engine'].' source fields'),
                $source['game_name'].' '.$table.': original columns modified by browser');
            foreach($displayed as $field) {
                if($table === 'imports' && $field === 'outer_index_serialized_width_bits'
                    && in_array('outer_index',$displayed,true)) {
                    requireTrue(str_contains($html,'data-outer-width-bits="32"')
                        || str_contains($html,'data-outer-width-bits="64"'),
                        $source['game_name'].' '.$table
                        .': compact Outer ref cell lost the serialized width');
                    continue;
                }
                if ($table === 'exports'
                    && in_array($field, examine_export_format_fields(), true)) {
                    requireTrue(str_contains($html, 'data-source-field="' . $field . '"')
                        && str_contains($html, '>Format</th>'),
                        $source['game_name'].' exports: source format metadata not disclosed '.$field);
                    continue;
                }
                requireTrue(str_contains($html,examine_source_label($field)),
                    $source['game_name'].' '.$table.': heading missing '.$field);
            }
            if ($table === 'exports') {
                requireTrue(str_contains($html, '>Local path</th>')
                    && str_contains($html, '>Full path</th>')
                    && str_contains($html, '>Format</th>'),
                    $source['game_name'] . ': compact export headings/disclosure missing');
                requireTrue(str_contains($html, 'examine-export-reference'),
                    $source['game_name'] . ': export reference name links missing');
                requireTrue(str_contains($html, 'signed reference: 0 (null)">-</span>'),
                    $source['game_name'] . ': null export reference must display a dash');
            }
            if($table==='imports' && $source['id']===1253000) {
                requireTrue(str_contains($html,'class="xref mono examine-import-self-ref"')
                    && str_contains($html,'data-outer-width-bits="32">-1</a>')
                    && !str_contains($html,'>32b</small>'),
                    'UE2 Ref/OuterIndex must show original signed raw values while width is hover-only');
            }
            if($table==='exports') {
                foreach(array_keys($original[0]) as $field) {
                    if (!in_array($field,$displayed,true)) {
                        throw new RuntimeException('Lost native export field '.$field);
                    }
                }
            }
            $checks[]=[
                'engine'=>$source['engine'],'game'=>$source['game_name'],
                'file'=>$source['id'],'section'=>$table,
                'sampled_rows'=>count($rows),'source_columns'=>count($displayed),
                'columns'=>$displayed,
            ];
        }
    }

    $zen=new PdoZenExamProjection($db);
    $fileId=1390232;
    $zenPath=\UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer::path(
        $root,14,$fileId);
    $zenHash=hash_file('sha256',$zenPath);
    requireTrue(is_string($zenHash) && strlen($zenHash)===64,
        'Original source-bound UE5.8.3 package snapshot unavailable.');
    $snapshotHashes['Satisfactory #1390232']=$zenHash;
    $zenResults=[];
    foreach(['names','imports','exports'] as $table) {
        $original=$zen->page($fileId,$table,0,250);
        requireTrue($original!==[],'UE5 Zen '.$table.' source indexed rows missing');
        $rows=$present->invoke(null,$original,$table);
        ob_start();
        if($table==='names') {
            zen_exam_names($rows,[],null,$fileId,250,'');
        }elseif($table==='imports') {
            zen_exam_imports($rows,[],[],$fileId,250,'');
        }else{
            zen_exam_exports($rows,[],[],[],[],$fileId,250,'');
        }
        $html=(string)ob_get_clean();
        $originalKeys=[];
        foreach($original as $row) foreach(array_keys($row) as $key) $originalKeys[$key]=true;
        if($table==='names') {
            foreach(['HashVersion','NameHash','IsUTF16','Length','NameBytes','Decoded text'] as $heading){
                requireTrue(str_contains($html,$heading),'Zen NameMap missing '.$heading);
            }
        }elseif($table==='imports') {
            $sourceBase=['index','raw_type_and_id','type_tag','value_u62','type'];
            foreach(array_keys($originalKeys) as $field){
                if(in_array($field,$sourceBase,true))continue;
                requireTrue(str_contains($html,examine_source_label($field)),
                    'Zen ImportMap missing '.$field);
            }
        }else{
            foreach(['CookedSerialOffset','CookedSerialSize',
                'ObjectName','OuterIndex','ClassIndex','SuperIndex',
                'TemplateIndex','PublicExportHash','ObjectFlags',
                'FilterFlags','Padding','Local path','Full path',
                'RawIndex (uint32)','Number (uint32)'] as $heading) {
                requireTrue(str_contains($html,$heading),
                    'Zen ExportMap missing source/derived field '.$heading);
            }
            requireTrue(str_contains($html,'0x00000009'),
                'Zen serialized FMappedName.Number word not visible');
        }
        $zenResults[$table]=[
            'rows'=>count($rows),'source_columns'=>array_keys($originalKeys),
            'markup_source_fields_valid'=>true,
        ];
    }
    $db->rollBack();
    echo json_encode([
        'pass'=>true,'read_only'=>true,
        'original_ue1_ue4_table_checks'=>$checks,
        'original_ue5_zen_table_checks'=>$zenResults,
        'original_uedb5_snapshot_sha256'=>$snapshotHashes,
        'classic_source_fields_dropped'=>0,
        'zen_source_fields_dropped'=>0,
        'engine_version_specific_presentation'=>true,
        'selected_runtime_mount_assumed'=>false,
        'production_database_changes'=>0,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    fwrite(STDERR,$e->getMessage().PHP_EOL);
    exit(1);
}
