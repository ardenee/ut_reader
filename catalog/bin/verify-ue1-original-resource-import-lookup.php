#!/usr/bin/env php
<?php
/**
 * Source-defined 1996 FGlobalResourceManager::FindResource / AddFile:
 * v0.82 SRC/ENGINE/UnRes.cpp:518-544,959-967;
 * v0.83/v0.84a Src/Engine/UnRes.cpp:521-550,1007-1019.
 * The native import lookup uses already-loaded ResArray, NOT file paths,
 * class packages, ResVersionNumber or resource header size.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
use UnrealDb\Catalog\Domain\Package\CatalogUE1OriginalResourceImportResolver as R;

$checks=[];$fails=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fails):void{
    $checks[$name]=$ok;
    if(!$ok){$fails[]=$name;}
};
$imports=[
    ['index'=>0,'name'=>'ImportedTex','resource_type'=>3,'resource_version'=>999,'resource_header_size'=>76],
    ['index'=>1,'name'=>'OtherTex','resource_type'=>3,'resource_version'=>17,'resource_header_size'=>12],
];
$unknown=R::project($imports);
$check('missing_runtime_inventory_not_false_missing',
    ($unknown[0]['status']??null)==='runtime_registry_not_available'
    && ($unknown[1]['status']??null)==='runtime_registry_not_available'
    && array_key_exists('runtime_object_loaded',$unknown[0])
    && $unknown[0]['runtime_object_loaded']===null);
$check('source_lookup_contains_no_package_paths',
    !isset($unknown[0]['package_name']) && !isset($unknown[0]['outer_index'])
    && ($unknown[0]['lookup_contract']??null)==='global_loaded_resarray_type_and_name');
$check('empty_supplied_registry_is_not_found',
    (R::project($imports,[])[0]['status']??null)==='not_found_in_supplied_registry');
$inventory=[
    ['name'=>'IMPORTEDTEX','resource_type'=>4,'resource_version'=>999],
    null,
    ['name'=>'OtherTex','resource_type'=>3,'resource_version'=>500],
    ['name'=>'importedtex','resource_type'=>3,'resource_version'=>1,'resource_header_size'=>41],
    ['name'=>'ImportedTex','resource_type'=>3,'resource_version'=>999],
];
$matches=R::project($imports,$inventory);
$check('ascii_case_insensitive_name',($matches[0]['status']??null)==='matched_supplied_registry');
$check('native_resource_type_must_match',($matches[0]['loaded_resource_slot']??null)===3);
$check('first_global_slot_wins',($matches[0]['loaded_resource_slot']??null)===3);
$check('resource_version_is_not_lookup_key',($matches[0]['loaded_resource_slot']??null)===3
    && $imports[0]['resource_version']!==$inventory[3]['resource_version']);
$check('resource_header_size_not_lookup_key',($matches[0]['loaded_resource_slot']??null)===3
    && $imports[0]['resource_header_size']!==$inventory[3]['resource_header_size']);
$check('independent_native_import_indices',($matches[1]['import_index']??null)===1
    && ($matches[1]['loaded_resource_slot']??null)===2);
$check('no_runtime_object_loaded_claim',array_key_exists('runtime_object_loaded',$matches[0])
    && $matches[0]['runtime_object_loaded']===null);
$sparse=[0=>null,7=>['name'=>'ImportedTex','resource_type'=>3]];
$check('empty_resarray_slots_skipped', (R::project([$imports[0]],$sparse)[0]['loaded_resource_slot']??null)===7);
$check('wrong_type_no_match', (R::project([$imports[0]],[['name'=>'ImportedTex','resource_type'=>4]])[0]['status']??null)
    ==='not_found_in_supplied_registry');
$check('no_fabricated_package_identity', !isset($matches[0]['provider_file_id'])
    && !isset($matches[0]['resolved_export_index']));
try{R::project([['name'=>'MissingType']],[]);$error=false;}
catch(InvalidArgumentException){$error=true;}
$check('malformed_import_rejected',$error);
try{R::project([$imports[0]],[['name'=>'InvalidSlot']]);$error=false;}
catch(InvalidArgumentException){$error=true;}
$check('malformed_runtime_slot_rejected',$error);
$roundtrip=json_decode(json_encode($matches,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
$check('json_roundtrip_preserves_lookup',$roundtrip===$matches);
echo json_encode(['ok'=>$fails===[],'passed'=>count($checks)-count($fails),
    'total'=>count($checks),'failures'=>$fails],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
