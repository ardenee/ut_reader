#!/usr/bin/env php
<?php
declare(strict_types=1);
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;

$checks=[]; $failures=[];
$check=static function(string $name,bool $ok) use (&$checks,&$failures):void{
    $checks[$name]=$ok; if(!$ok){$failures[]=$name;}
};
$snapshot=[
    'file'=>['id'=>42,'game_id'=>3,'package_name'=>'DM-Test','original_name'=>'DM-Test.unr'],
    'package_family'=>'classic-linkerload','source_policy'=>'fixture',
    'sections'=>[
        'names'=>[
            ['index'=>0,'text'=>'Foo'],['index'=>1,'text'=>'foo'],['index'=>2,'text'=>'Bar'],
        ],
        'exports'=>[
            ['index'=>0,'object_name'=>['text'=>'Foo'],'class_index'=>-1,'outer_index'=>0,'object_flags'=>'00000001'],
            ['index'=>1,'object_name'=>['text'=>'Baz'],'public_export_hash'=>'0123456789ABCDEF','serial_size'=>10],
        ],
    ],
];
$registration=[
    'file_id'=>42,'game_id'=>3,
    'package_key_kind'=>Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_NAME,
    'package_key'=>md5('dm-test',true),
];
$projection=Uedb5SqlProjectionBuilder::build($snapshot,$registration);
$check('one_primary_provider_key',count($projection['provider_keys'])===1);
$check('fname_candidates_deduplicate_case_insensitively',count($projection['name_candidates'])===2);
$check('object_candidates_are_one_per_export',count($projection['object_candidates'])===2);
$check('search_dictionary_is_distinct',count($projection['search_keys'])===4);
$forbidden=['class_index','outer_index','object_flags','serial_size','serial_offset'];
$objectProjectionOk=true;
foreach($projection['object_candidates'] as $row){
    foreach($forbidden as $field){ if(array_key_exists($field,$row)){$objectProjectionOk=false;} }
}
$check('object_projection_does_not_copy_source_graph',$objectProjectionOk);
$check('name_candidates_do_not_repeat_text',!array_key_exists('text',$projection['name_candidates'][0] ?? []));
$check('public_export_hash_is_binary_8_bytes',strlen((string)($projection['object_candidates'][1]['public_export_hash'] ?? ''))===8);
$check('base_pass_leaves_dependencies_for_second_pass',$projection['dependency_edges']===[] && $projection['dependency_packages']===[]);

$publisherSource=(string)file_get_contents($root.'/src/Infrastructure/Metadata/PdoUedb5BaseProjectionPublisher.php');
$check('publisher_never_writes_live_v4_registration',!str_contains($publisherSource,'ue_file_metadata'));
$check('publisher_clears_stale_dependency_projection',str_contains($publisherSource,"'ue_uedb5_dependency_edges'") && str_contains($publisherSource,"'ue_uedb5_dependency_packages'"));
$check('publisher_uses_staged_v5_tables',str_contains($publisherSource,'ue_uedb5_object_candidates') && str_contains($publisherSource,'ue_uedb5_name_candidates'));
$check('publisher_batches_large_projection_inserts',
    substr_count($publisherSource,'$this->insertBatches(')>=3
    && str_contains($publisherSource,'array_chunk($rows, 250)')
    && !str_contains($publisherSource,"foreach ((array)\$projection['object_candidates'] as \$row)"));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
