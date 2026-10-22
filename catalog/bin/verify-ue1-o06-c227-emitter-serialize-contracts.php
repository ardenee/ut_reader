#!/usr/bin/env php
<?php
/** Source contracts for v1.227 emitter file-save, GC-only and transient cases.
 * This is NOT a native engine execution oracle or serialization of packages.
 */
declare(strict_types=1);
$root=rtrim('L:\Source\Games\Unreal\Unreal [v1.227]','\\');
$repo=dirname(__DIR__,2);
$register=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$entries=array_column($register['entries'],null,'id');
$cases=[
 'AEmitterGarbageCollector'=>['Emitter/Src/EmitterRender.cpp','AInfo,CLASS_Transient',
   ['if (GarbagePtr && Ar.SerializeRefs())','(*GarbagePtr)(i)->Serialize(Ar);','Super::Serialize(Ar);']],
 'AXBeamEmitter'=>['Emitter/Src/BeamEmitter.cpp','AXEmitter,CLASS_Abstract',
   ['Super::Serialize(Ar);','if (Ar.SerializeRefs())','Ar << RenderDataModel;']],
 'AXParticleEmitter'=>['Emitter/Src/AXEmitter.cpp','AActor,CLASS_Abstract',
   ['if (Ar.IsSaving() && bIsInternalEmitter)','Location = FVector(0, 0, 0);',
    'Rotation = FRotator(0, 0, 0);','Level = NULL;','XLevel = NULL;',
    'HitActor = NULL;','Super::Serialize(Ar);','if (!Ar.IsLoading() && !Ar.IsSaving() && PartPtr)']],
 'AXRopeDeco'=>['Emitter/Src/EmitterPhysX.cpp','ADecoration,0',
   ['if (Ar.SerializeRefs())','Ar << RopeMeshPtr;',
    'else if (Ar.IsSaving())','Mesh = nullptr;','Super::Serialize(Ar);',
    'Mesh = RopeMeshPtr;']],
 'UBeamMesh'=>['Emitter/Src/BeamEmitter.cpp','UStaticMesh,CLASS_Transient',
   ['UObject::Serialize(Ar); // Serialize nothing from StaticMesh']],
];
$checks=[];$failed=[];$hashes=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failed):void{
 $checks[$name]=$pass;if(!$pass)$failed[]=$name;
};
$hdrPath=$root.'\Emitter\Inc\EmitterClasses.h';
$hdr=@file_get_contents($hdrPath);
$meshHdrPath=$root.'\Emitter\Inc\UBeamMesh.h';
$meshHdr=@file_get_contents($meshHdrPath);
foreach([$hdrPath=>$hdr,$meshHdrPath=>$meshHdr] as $p=>$src){
 if(is_string($src)){$hashes[$p]=hash('sha256',$src);}
}
foreach($cases as $class=>[$rel,$macro,$snippets]){
 $entry=$entries['C227:'.$class.'::Serialize']??null;
 $check($class.':register_has_source_sighting',is_array($entry)
   &&isset($entry['locations'][0]['path'],$entry['locations'][0]['line']));
 $path=$root.'\\'.str_replace('/','\\',$rel);
 $data=@file_get_contents($path);
 if(is_string($data)){$hashes[$path]=hash('sha256',$data);}
 $pos=is_string($data)?strpos($data,'void '.$class.'::Serialize('):false;
 $check($class.':pinned_source_method_found',$pos!==false);
 $body=$pos===false?'':substr($data,$pos,2000);
 foreach($snippets as $part){
  $check($class.':'.substr(hash('sha256',$part),0,8),str_contains($body,$part));
 }
 $declarations=$class==='UBeamMesh'?$meshHdr:$hdr;
 $check($class.':class_declaration_flags',is_string($declarations)
   &&str_contains($declarations,'DECLARE_CLASS('.$class.','.$macro.',Emitter)'));
}
ksort($hashes);
echo json_encode(['ok'=>$failed===[], 'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'source_files'=>count($hashes),
 'source_sha256_manifest'=>hash('sha256',json_encode($hashes,JSON_UNESCAPED_SLASHES)),
 'exclusions'=>['AEmitterGarbageCollector','UBeamMesh'],
 'saveable_actor_contracts_open'=>['AXBeamEmitter','AXParticleEmitter','AXRopeDeco'],
 'scope'=>'Only C227 emitter overrides and declarations; saving parent property effects not implemented here'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
