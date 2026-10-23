#!/usr/bin/env php
<?php
/** UT99 native FString archived values: signed character counts and byte identity. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassDefaultSchemaReader as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/';
$unmisc=@file_get_contents($root.'UnMisc.cpp');
$unprop=@file_get_contents($root.'UnProp.cpp');
$checks=[];$fail=[];$samples=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$check('source_signed_character_count_encoding',
 is_string($unmisc)
 &&str_contains($unmisc,'INT SaveNum = appIsPureAnsi(*A) ? A.Num() : -A.Num();')
 &&str_contains($unmisc,'Ar << AR_INDEX(SaveNum);'));
$check('source_ansi_and_unicode_archive_operators',
 is_string($unmisc)
 &&str_contains($unmisc,'A(i)=FromAnsi(ACh);')
 &&str_contains($unmisc,'A(i)=FromUnicode(UCh);')
 &&str_contains($unmisc,'if( Ar.IsLoading() && A.Num()==1 )'));
$check('source_UStrProperty_loads_FString',
 is_string($unprop)
 &&str_contains($unprop,'void UStrProperty::SerializeItem( FArchive& Ar, void* Value ) const')
 &&str_contains($unprop,'Ar << *(FString*)Value;'));
$compact=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$first=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($first);
 $out=chr($first|64);
 do{$b=$n&127;$n>>=7;$out.=chr($b|($n?128:0));}while($n);
 return $out;
};
$build=static function(int $version,string $serialized)
 use($compact):string{
 $names=['None','StrProperty','TestProperty','Core','Class','Gameplay'];
 $nbytes='';
 foreach($names as $name){
  $nbytes.=($version>=64?$compact(strlen($name)+1):'')
    .$name."\0".pack('V',0);
 }
 $import=$compact(3).$compact(4).pack('V',0).$compact(1);
 $length=strlen($serialized);
 $tag=$compact(2).($length<=255?chr(0x5d).chr($length):
   chr(0x6d).pack('v',$length)).$serialized.$compact(0);
 $native=$compact(0).$compact(0).pack('V2',1,0).$compact(5);
 $body=$tag.$native;
 $hs=$version>=68?56:44;
 $io=$hs+strlen($nbytes);$eo=$io+strlen($import);
 $export=$compact(-1).$compact(0).pack('V',0).$compact(2)
   .pack('V',0).$compact(strlen($body)).$compact(512);
 $hdr=pack('V9',0x9e2a83c1,$version,0,count($names),$hs,
     1,$eo,1,$io);
 $hdr.=$version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
 $tables=$hdr.$nbytes.$import.$export;
 if(strlen($tables)>512)throw new RuntimeException('Package fixture tables exceed limit');
 return $tables.str_repeat("\0",512-strlen($tables)).$body;
};
$run=static function(string $label,int $version,string $bytes,
  string $expectKind,?int $count=null,?string $ascii=null)
 use($build,$check,&$samples):void {
 $path=tempnam(sys_get_temp_dir(),'u1fstring');
 if($path===false)throw new RuntimeException('Temp file creation failed');
 try{
  file_put_contents($path,$build($version,$bytes));
  $pkg=new P($path);
  $check($label.'_valid_package',$pkg->validatePackage()===[]);
  $tagInfo=T::inspect($path,$pkg,0);
  $tag=$tagInfo['tags'][0];
  $check($label.'_source_tag_kind_and_size',
    $tag['type']==='StrProperty'
    &&$tag['serialized_size']===strlen($bytes)
    &&$tag['value_kind']===$expectKind
    &&$tag['value_sha256']===hash('sha256',$bytes));
  $native=N::inspect($path,$pkg,0,$tagInfo);
  $check($label.'_real_UStrProperty_native_fields',
    $native['status']==='native_property_record_complete'
    &&$native['property_kind']==='strproperty'
    &&$native['array_dim']===1);
  $class=['export_index'=>0,'children_reference'=>1,'super_field_reference'=>0];
  $schema=S::inspect($path,$pkg,$class,$tagInfo['tags']);
  $check($label.'_schema_uses_correct_counted_archive',
    $expectKind==='opaque'?
      ($schema['status']==='dynamic_fstring_archive_unverified'
       &&$schema['dynamic_fstring_archive_unverified']===1
       &&$schema['declaration_type_and_array_index_matches']===0):
      ($schema['status']==='all_available_declarations_match'
       &&$schema['dynamic_fstring_archive_unverified']===0
       &&$schema['declaration_type_and_array_index_matches']===1
       &&$tag['value']['signed_character_count']===$count
       &&$tag['value']['ascii_text']===$ascii
       &&$tag['value']['runtime_locale_conversion_verified']===false));
  if($expectKind!=='opaque'){
   $positive=$count>=0;
   $payload=substr($bytes,$count>=0?
     strlen($bytes)-abs($count):strlen($bytes)-abs($count)*2);
   $check($label.'_payload_reencodes_exactly',
     $tag['value']['serialized_character_bytes_hex']===bin2hex($payload)
     &&$tag['value']['archive_encoding']===
       ($positive?'ansi_8bit':'unicode_16bit_le'));
  }
  $samples[]=['test'=>$label,'version'=>$version,
    'on_disk_bytes'=>strlen($bytes),'kind'=>$tag['value_kind'],
    'signed_count'=>$tag['value']['signed_character_count']??null];
 }finally{unlink($path);}
};
$run('ansi_hello',69,$compact(6)."Hello\0",
 'fstring_ansi_archive',6,'Hello');
$run('ansi_nonascii',69,$compact(2)."\xe9\0",
 'fstring_ansi_archive',2,null);
$run('unicode_two_chars',69,$compact(-3).pack('v3',0x41,0x03a9,0),
 'fstring_utf16_archive',-3,null);
$run('unicode_ascii',69,$compact(-3).pack('v3',0x41,0x42,0),
 'fstring_utf16_archive',-3,'AB');
$run('unicode_empty',69,$compact(-1).pack('v',0),
 'fstring_utf16_archive',-1,'');
$run('ansi_zero_count_empty',69,$compact(0),
 'fstring_ansi_archive',0,'');
$run('ansi_nul_only_empty',69,$compact(1)."\0",
 'fstring_ansi_archive',1,'');
$run('ansi_over_255_byte_tag',69,$compact(301).str_repeat('A',300)."\0",
 'fstring_ansi_archive',301,str_repeat('A',300));
$run('bad_count_length',69,$compact(4)."X\0",'opaque');
$run('bad_unicode_count_length',69,$compact(-2).pack('v',0),'opaque');
$run('bad_missing_ansi_terminator',69,$compact(3).'ABC','opaque');
$run('bad_missing_unicode_terminator',69,$compact(-2).pack('v2',0x41,0x42),'opaque');
echo json_encode(['ok'=>$fail===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($fail),'failed'=>$fail,
 'samples'=>$samples,'source_sha256'=>[
 'UnMisc.cpp'=>is_string($unmisc)?hash('sha256',$unmisc):null,
 'UnProp.cpp'=>is_string($unprop)?hash('sha256',$unprop):null],
 'remaining'=>'FString character count/data binary archive verified source-wise, but ANSI/Unicode runtime character conversion, memory allocation and actual compiled engine parity remain unverified.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
