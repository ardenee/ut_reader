#!/usr/bin/env php
<?php
/** Pure, source-backed compact V5 projection tests; no SQL or storage writes. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5CompactCandidateProjectionBuilder as Compact;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as K;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash as H;
function entry(string $text,int $id): array {
    $n=H::nameKey($text);
    return ['name_id'=>$id,'key_hash'=>md5($n,true),'key_length'=>strlen($n),
        'key_fingerprint'=>hash('sha256',$n,true),'normalized_text'=>$n];
}
function candidate(string $text,int $fileId,int $index): array {
    $n=H::nameKey($text);
    return ['file_id'=>$fileId,'name_key_hash'=>md5($n,true),'name_key_length'=>strlen($n),
        'name_key_fingerprint'=>hash('sha256',$n,true),'first_name_index'=>$index];
}
$dictionary=[entry('Example',11),entry('Other',12)];
$snapshot=['file'=>['id'=>9],'sections'=>[
  'names'=>[['index'=>0,'text'=>'Example']],
  'exports'=>[['index'=>0,'object_name'=>'Other']]]];
$projection=[
  'name_candidates'=>[candidate('Example',9,0)],
  'object_candidates'=>[[
    'file_id'=>9,'object_kind'=>K::OBJECT_KIND_EXPORT,'object_index'=>0,
    'object_name_hash'=>md5(H::nameKey('Other'),true),
    'object_name_length'=>strlen(H::nameKey('Other')),'public_export_hash'=>null]]];
$checks=[];
$run=function(string $name,callable $fn)use(&$checks):void{
  try{$fn();$checks[]=['check'=>$name,'ok'=>true];}
  catch(Throwable $e){$checks[]=['check'=>$name,'ok'=>false,'error'=>$e->getMessage()];}
};
$mustThrow=function(callable $fn):void{
  try{$fn();}catch(RuntimeException){return;}
  throw new RuntimeException('Expected strict rejection.');
};
$run('source_accurate_name_and_object',function()use($snapshot,$projection,$dictionary){
  $r=Compact::build($snapshot,$projection,$dictionary);
  if($r['name_candidates'][0]['name_id']!==11 || $r['object_candidates'][0]['name_id']!==12)
    throw new RuntimeException('Wrong compact ID.');
  if($r['object_candidates'][0]['public_export_hash']!==null)
    throw new RuntimeException('Public hash NULL not preserved.');
});
$run('object_hash_tampering_rejected',function()use($snapshot,$projection,$dictionary,$mustThrow){
  $p=$projection;$p['object_candidates'][0]['object_name_hash']=str_repeat('z',16);
  $mustThrow(fn()=>Compact::build($snapshot,$p,$dictionary));
});
$run('source_name_mismatch_rejected',function()use($snapshot,$projection,$dictionary,$mustThrow){
  $s=$snapshot;$s['sections']['exports'][0]['object_name']='Changed';
  $mustThrow(fn()=>Compact::build($s,$projection,$dictionary));
});
$run('dictionary_missing_fingerprint_rejected',function()use($snapshot,$projection,$dictionary,$mustThrow){
  $mustThrow(fn()=>Compact::build($snapshot,$projection,[$dictionary[0]]));
});
$run('dictionary_digest_collision_or_corruption_rejected',function()use($snapshot,$projection,$dictionary,$mustThrow){
  $d=$dictionary;$d[1]['key_hash']=$d[0]['key_hash'];$d[1]['key_length']=$d[0]['key_length'];
  $mustThrow(fn()=>Compact::build($snapshot,$projection,$d));
});
$run('duplicate_dictionary_id_rejected',function()use($snapshot,$projection,$dictionary,$mustThrow){
  $d=$dictionary;$d[1]['name_id']=$d[0]['name_id'];
  $mustThrow(fn()=>Compact::build($snapshot,$projection,$d));
});
$run('filename_origin_identity_rejected',function()use($snapshot,$projection,$dictionary,$mustThrow){
  $p=$projection;$p['name_candidates'][0]['file_id']=10;
  $mustThrow(fn()=>Compact::build($snapshot,$p,$dictionary));
});
$failed=array_values(array_filter($checks,static fn(array $c)=>!$c['ok']));
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),'failures'=>$failed,
  'source_only'=>true,'database_writes'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:2);
