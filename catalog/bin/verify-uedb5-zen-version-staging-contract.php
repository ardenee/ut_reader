<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ValidationException;
// Call the real final Step 8 Zen contract without a production DB connection.
$validator=(new ReflectionClass(Uedb5MigrationValidator::class))->newInstanceWithoutConstructor();
$method=(new ReflectionClass(Uedb5MigrationValidator::class))->getMethod('validateZenFields');
$check=[];$fail=[];
$record=static function(string $name,bool $ok)use(&$check,&$fail):void{
    $check[$name]=$ok;if(!$ok)$fail[]=$name;
};
$base=[
    'package_family'=>'zen-iostore',
    'sections'=>[
        'container_provenance'=>[],
        'package_store'=>[],
        'name_map'=>[],
        'imports'=>[],
        'exports'=>[],
        'cell_imports'=>[],
        'cell_exports'=>[],
        'dependency_bundle_entries'=>[],
        'bulk_data_map'=>[],
        'optional_segment'=>[],
        'package_summary'=>[[
            'package_id'=>'0123456789ABCDEF',
            'summary'=>[
                'has_versioning_info'=>1,
                'export_bundle_entries_offset'=>128
            ],
            'versioning_info'=>[
                'zen_version'=>3,
                'package_version'=>['ue4'=>522,'ue5'=>1015],
                'licensee_version'=>0,
                'custom_versions'=>[
                    ['index'=>0,'key_raw'=>str_repeat('01',16),
                     'key_words'=>['01010101','01010101','01010101','01010101'],
                     'version'=>7]
                ]
            ],
            'cell_offsets'=>[
                'cell_import_map_offset'=>120,
                'cell_export_map_offset'=>128,
                'serialized'=>true
            ],
            'header_bytes'=>196,
            'exports_data_bytes'=>4,
        ]]
    ]
];
$try=static function(array $snap)use($method,$validator):?string{
    try{$method->invoke($validator,$snap);return null;}
    catch(Uedb5ValidationException $e){return (string)$e->reasonCode;}
    catch(Throwable $e){return 'unexpected_'.get_class($e);}
};
$record('valid_versioned_1015_passes',$try($base)===null);
$variant=$base;$variant['sections']['package_summary'][0]['versioning_info']=null;
$record('serialized_version_flag_without_version_data_rejected',$try($variant)!==null);
$variant=$base;$variant['sections']['package_summary'][0]['summary']['has_versioning_info']=0;
$record('unversioned_flag_with_serialized_version_data_rejected',$try($variant)!==null);
$variant=$base;$variant['sections']['package_summary'][0]['versioning_info']['package_version']['ue5']=1014;
$record('pre_verse_cells_serialized_offsets_rejected',$try($variant)!==null);
$variant=$base;$variant['sections']['package_summary'][0]['versioning_info']['package_version']['ue5']=1008;
$variant['sections']['bulk_data_map']=[['index'=>0,'cooked_index'=>0]];
$record('pre_data_resources_bulk_rows_rejected',$try($variant)!==null);
$variant=$base;$variant['sections']['package_summary'][0]['versioning_info']=null;
$variant['sections']['package_summary'][0]['summary']['has_versioning_info']=0;
$record('valid_unversioned_latest_layout_passes',$try($variant)===null);
$variant=$base;$variant['sections']['package_summary'][0]['versioning_info']['package_version']['ue5']=1014;
$variant['sections']['package_summary'][0]['cell_offsets']=[
    'serialized'=>false,'cell_import_map_offset'=>128,'cell_export_map_offset'=>128];
$record('valid_versioned_1014_pre_cells_passes',$try($variant)===null);
$variant=$base;$variant['sections']['package_summary'][0]['versioning_info']['package_version']['ue5']=1008;
$variant['sections']['package_summary'][0]['cell_offsets']=[
    'serialized'=>false,'cell_import_map_offset'=>128,'cell_export_map_offset'=>128];
$record('valid_versioned_1008_pre_bulk_passes',$try($variant)===null);
$variant=$base;unset($variant['sections']['package_summary'][0]['cell_offsets']);
$record('missing_persisted_cell_offsets_rejected',$try($variant)!==null);
$variant=$base;unset($variant['sections']['bulk_data_map']);
$record('missing_persisted_bulk_map_section_rejected',$try($variant)!==null);
$variant=$base;$variant['sections']['optional_segment']=[[
    'summary'=>['has_versioning_info'=>1,'export_bundle_entries_offset'=>100],
    'versioning_info'=>null,
    'cell_offsets'=>['serialized'=>true,'cell_import_map_offset'=>100,'cell_export_map_offset'=>100],
    'bulk_data_map'=>[]
]];
$record('malformed_optional_segment_versioning_rejected',$try($variant)!==null);
$variant=$base;$variant['sections']['package_summary'][0]['summary']['has_versioning_info']=2;
$record('noncanonical_nonzero_serialized_flag_accepted',$try($variant)===null);
echo json_encode(['ok'=>$fail===[],'checks'=>$check,'failures'=>$fail],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
