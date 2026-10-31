#!/usr/bin/env php
<?php
/** Frozen original compiled UT99 469f T017+T059 actor class default acceptance.
 * One genuine native GameInfo/Pawn/HUD load verifies mixed Bool/Int/Name and
 * inherited Actor.bHidden false / HUD override true. v432 88-class compiled
 * FULL acceptance reused; no original engine replay during verification.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$ev=$repo.'/catalog/compliance/evidence/o06/';
$root='C:/Temp/UnrealDB/O06/';
$checks=0;$failures=[];
$ck=static function(bool $ok,string $note)use(&$checks,&$failures):void{
 ++$checks;if(!$ok)$failures[]=$note;
};
foreach([
 [$root.'Native-469f/System/Core.dll','80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2'],
 [$root.'Native-469f/System/Engine.dll','625dabea278e314714a45c931c98ba6282a9198378499144429aa979a1a0570f'],
 [$root.'Native-469f/System/Engine.u','6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b'],
 [$root.'Native-UCC/System/Core.dll','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'],
 [$root.'Native-UCC/System/Engine.u','d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695'],
 [$ev.'20261029-G3-T017-T059-original-469f-three-actor-native-defaults.log','c3c7fa3246e9ad41d6a03556852d0a0f726e94e1ea31c5aa1d442af1802acb99'],
 [$ev.'20261029-G3-T017-T059-original-469f-three-actor-native-probe.cpp','a770685fe57d2afee5a171c1cf7cabb3e4fb1ebdf63172982f4a658073aa6434'],
 [$ev.'20261029-G3-T017-T059-original-v432-469f-actor-source.json','936faa24264f8adba488eb28fc877b52e0eab9846feb027b12e8573e0b7cc67f'],
 [$ev.'20261029-G3-T014-original-469f-UClass-856pass.json','0b0b1025101dfa0e602680feeb8858798ac6de7c1f04bc4911484bb7cba48c6f'],
 [$repo.'/catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json','772b85769a1773ab80bdfe2be278846e3d1c2977a5fd28eb2fef8e9df0049f7e'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','df4a525783fa7667ac14f405194e6ea8f2db8a5a22615b228ebbb6f59c33b35a']
] as [$path,$expected])$ck(is_file($path)&&hash_file('sha256',$path)===$expected,'original actual source/native identity '.$path);
$old=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json'),true,512,JSON_THROW_ON_ERROR);
$ck($old['decision']==='FULL'&&$old['all_real_original_UClass_native_loaded']===88
 &&$old['original_tagged_class_defaults_stock_native_values']===608,
 'Previously original compiled v432 Engine.u FULL 88 classes and 608 stock loaded defaults reused, without native replay');
$new=json_decode((string)file_get_contents($ev.'20261029-G3-T014-original-469f-UClass-856pass.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($new['ok']??false)===true&&($new['checks']??0)===856&&($new['original_469f_serialized_UClass_compared_to_native']??0)===88,
 'Previously compiled original 469f 88 UClass original loaded field native acceptance reused');
$live=str_replace("\r\n","\n",(string)file_get_contents($ev.'20261029-G3-T017-T059-original-469f-three-actor-native-defaults.log'));
$ck(str_contains($live,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
 &&str_contains($live,'native_error=0')&&str_contains($live,'G5_NATIVE_OBJECT_COUNT 3'),
 'Original compiled 469f Core/Engine really loads Engine.u and three actual actor CDOs');
$native=[];
foreach(['Pawn','HUD','GameInfo'] as $name){
 $pattern='~G3_ORIGINAL_469F_ACTOR_DEFAULT_BEGIN Class Engine\.'.$name.'\|defaultPtr=([0-9A-Fa-f]+)\n(.*?)G3_ORIGINAL_469F_ACTOR_DEFAULT_END Class Engine\.'.$name.'~s';
 $found=preg_match($pattern,$live,$m);
 $ck($found===1,'Original live compiled 469f CDO region exists '.$name);
 if($found!==1)continue;
 $v=[];
 preg_match_all('/^G5_NATIVE_TAG_VALUE\s+([A-Za-z_][A-Za-z0-9_]*)=(.*)$/m',$m[2],$rows,PREG_SET_ORDER);
 foreach($rows as $row)$v[$row[1]]=trim($row[2]);
 $ck(count($v)>0,'actual compiled original 469f CDO named field output nonempty '.$name);
 $native[$name]=$v;
}
$ck(count($native)===3,'Three real original compiled actor class CDOs native loaded');
$families=[
 'v432'=>[$root.'Native-UCC/System/Engine.u','ut99-v432'],
 'v469f'=>[$root.'Native-469f/System/Engine.u','ut99-v469f']
];
$types=['bPauseable'=>'BoolProperty','MaxPlayers'=>'IntProperty','DefaultPlayerState'=>'NameProperty'];
$values=['bPauseable'=>true,'MaxPlayers'=>16,'DefaultPlayerState'=>63];
$byFamily=[];$tagsByFamily=[];
foreach($families as $name=>[$file,$family]){
 $p=new P($file);$exports=$p->getExports();$classes=[];
 $ck((int)$p->getHeader()['version']===69,'Original UT99 '.$name.' v69 archive source gate');
 foreach($exports as $i=>$e){
  if((int)$e['classIndex']!==0||!in_array($e['objectNameText'],['Actor','Pawn','HUD','GameInfo'],true))continue;
  $v=C::inspect($file,$p,$i,false,$family);
  $cn=$e['objectNameText'];
  $ref=(int)($v['super_field_reference']??0);
  $super=$ref>0?($exports[$ref-1]['objectNameText']??null):null;
  $ck($v['source_file_archive_complete']===true,'Genuine '.$name.' fully consumed UClass original physical export '.$cn);
  $ck(($v['defaults_tag_count']??-1)===count($v['default_property_tags']??[]),
    'Genuine '.$name.' number of terminated original UClass default FPropertyTags '.$cn);
  $tags=$v['default_property_tags']??[];
  $last=-1;
  foreach($tags as $tag){
    $offset=(int)($tag['tag_offset']??-1);
    $ck($offset>$last,'Genuine '.$name.' strict physical original tag byte order '.$cn.' at '.$offset);
    $last=$offset;
  }
  $by=[];
  foreach($tags as $ordinal=>$tag){
    $t=$tag['name']??'';
    if(in_array($t,['bHidden','bCanJump','Health','bPauseable','MaxPlayers','DefaultPlayerState'],true))
       $by[$t]=['type'=>$tag['type'],'value'=>$tag['value'],'ordinal'=>$ordinal,
        'offset'=>(int)$tag['tag_offset'],'name_index'=>(int)$tag['name_index']];
  }
  $classes[$cn]=['super'=>$super,'tags'=>$by,'tag_count'=>count($tags),
    'export'=>$i,'serial_end'=>(int)$e['serialOffset']+(int)$e['serialSize']];
 }
 $ck(count($classes)===4,'Four original UT99 '.$name.' real script/actor class archive source exports');
 $ck(($p->getNames()[63]['text']??null)==='PlayerWalking',
     'Original actual '.$name.' FName[63] resolves PlayerWalking');
 $byFamily[$name]=$classes;
}
$actor=$byFamily['v469f']['Actor'];$hud=$byFamily['v469f']['HUD'];$pawn=$byFamily['v469f']['Pawn'];
$ck($actor['super']===null&&$hud['super']==='Actor'&&$pawn['super']==='Actor',
    'Original 469f source real Pawn/HUD superclass Actor; Actor intrinsic superclass Object import');
$ck(($actor['tags']['bHidden']['value']??null)===false
    &&($hud['tags']['bHidden']['value']??null)===true
    &&!isset($pawn['tags']['bHidden']),
    'Original source Actor.bHidden false, HUD explicitly overrides true, Pawn inherits with no override');
$ck(($byFamily['v432']['HUD']['tags']['bHidden']['value']??null)===true
    &&$byFamily['v432']['HUD']['super']==='Actor',
    'Original compiled-accepted v432 HUD same Actor.bHidden true override, no 469f regression');
$ck(($native['Pawn']['bHidden']??null)==='False'
    &&($native['HUD']['bHidden']??null)==='True',
    'Original compiled 469f loaded Actor inherited default Pawn.bHidden=False and HUD override=True');
$gameArchive=[];
foreach(['v432','v469f'] as $profile){
 $game=$byFamily[$profile]['GameInfo']??null;
 $ck($game!==null&&$game['super']==='Info','Genuine '.$profile.' GameInfo is Actor-derived through Info');
 $tags=$game['tags']??[];
 $offset=-1;$order=[];
 foreach($types as $field=>$type){
   $t=$tags[$field]??null;
   $ck($t!==null&&$t['type']===$type&&$t['value']===$values[$field],
       'Original '.$profile.' tagged GameInfo '.$field.' exact Bool/Int/Name source type/value');
   if($t){
    $ck($t['offset']>$offset,'Original '.$profile.' GameInfo Bool then Int then Name physical tag order '.$field);
    $offset=$t['offset'];
    $order[]=$t['offset'];
   }
 }
 $gameArchive[$profile]=['class_export'=>$game['export'],
    'serialized_tags'=>$game['tag_count'],'native_compared_properties'=>$tags,'tag_offsets'=>$order];
}
$ck(($native['GameInfo']['bPauseable']??null)==='True',
    'Original compiled 469f real GameInfo saved Bool bPauseable loaded TRUE');
$ck(($native['GameInfo']['MaxPlayers']??null)==='16',
    'Original compiled 469f real GameInfo saved Int MaxPlayers loaded 16');
$ck(($native['GameInfo']['DefaultPlayerState']??null)==='"PlayerWalking"',
    'Original compiled 469f real GameInfo saved Name FName63 loaded PlayerWalking');
$ck(($gameArchive['v469f']['serialized_tags']??0)>($gameArchive['v432']['serialized_tags']??0),
    'Original v469f vs v432 actual changed archive tag quantities handled per source without inherited fixed order');
$ck(count($gameArchive['v469f']['tag_offsets']??[])===3
    &&count($gameArchive['v432']['tag_offsets']??[])===3,
    'Both original UT99 source profiles preserve unique mixed Bool/Int/Name physical archives');
$ok=$failures===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failures,
 'T017'=>$ok?'PASS':'BLOCKED','T059'=>$ok?'PASS':'BLOCKED',
 'original_469f_compiled_actor_default_classes'=>array_keys($native),
 'source_Actor_bHidden_false_loaded_inherited_Pawn'=>($native['Pawn']['bHidden']??null),
 'source_HUD_override_true_native'=>($native['HUD']['bHidden']??null),
 'original_compiled_mixed_GameInfo_loaded'=>array_intersect_key($native['GameInfo']??[],array_flip(array_keys($types))),
 'v432_and_469f_real_archive_tag_order'=>$gameArchive,
 'already_accepted_v432_native_UClass_classes'=>88,
 'original_compiled_native_sessions_rerun_by_verifier'=>0],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
