<?php
declare(strict_types=1);
/**
 * Epic UE5.8.3 UnrealPak vs UnrealDB PAK-11 read/decode acceptance.
 * Operates read-only on the original dedicated-server archive, and writes
 * only disposable small decoded samples into the OS temp directory.
 */
require dirname(__DIR__).'/lib/CatalogPakArchive.php';
$pak=getenv('UE583_SATISFACTORY_PAK') ?: 'C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.pak';
if(!is_file($pak)){fwrite(STDERR,"Missing real Satisfactory PAK fixture: $pak\n");exit(3);}
$footer=catalog_pak_footer_candidates($pak);
if(count($footer)!==1 || (int)$footer[0]['version']!==11){fwrite(STDERR,"Unexpected PAK footer/version\n");exit(2);}
$index=catalog_pak_parse_index($pak,$footer[0]);
$actual=[];foreach($index['entries'] as $row){$actual[$row['filename']]=$row;}
$expected=[
 'Engine/Config/Base.ini'=>['size'=>77,'sha1'=>'9989dacada951065d019d1c639dc0bd111ad24cd'],
 'Engine/Config/BaseCompat.ini'=>['size'=>16060,'sha1'=>'591dccd4f08c571a093dbc7b6d51669f74f02c21'],
 'Engine/Config/BaseGame.ini'=>['size'=>10179,'sha1'=>'eb16485cd46bce9b5d06dda6d589214b12680e41'],
 'Engine/Config/BaseGameUserSettings.ini'=>['size'=>64,'sha1'=>'e920e555442f8a0f68839146d2ca7cbb55e70af1'],
 'Engine/Config/BaseHardware.ini'=>['size'=>1986,'sha1'=>'a46ab3292f365ad2e884c9f9565d5f0a6e73fcc1'],
];
$results=[];$ok=count($actual)===4761;
foreach($expected as $name=>$expectedData){
 $tmp=tempnam(sys_get_temp_dir(),'uedb5-pak11-');
 try{
   $entry=$actual[$name]??null;
   $decoded=is_array($entry)&&catalog_pak_write_entry_data($pak,$entry,$tmp);
   $pass=$decoded && filesize($tmp)===$expectedData['size'] && hash_equals($expectedData['sha1'],sha1_file($tmp));
   $results[]=['name'=>$name,'compression'=>$entry['compression_name']??null,'ok'=>$pass];
   $ok=$pass&&$ok;
 }finally{if(is_file($tmp))unlink($tmp);}
}
// Optional full extraction exercises the actual production archive helper.
// All outputs remain under system temp and are deleted on completion/failure.
$full=null;
if(in_array('--full-extract',$argv,true)){
 $extractedRoot=null;
 try{
  $archive=catalog_pak_archive_extract_to_temp(
   ['pak_extract'=>['max_extracted_files'=>20000,'max_extracted_bytes'=>1073741824]],
   $pak,basename($pak)
  );
  $extractedRoot=$archive['dir'];
  $count=count($archive['files']);
  $full=['ok'=>$count===count($actual),'files'=>$count,
   'uncompressed_bytes'=>array_sum(array_column($archive['files'],'bytes'))];
  $ok=$ok&&$full['ok'];
 }finally{if(is_string($extractedRoot))catalog_pak_archive_delete_tree($extractedRoot);}
}
echo json_encode(['ok'=>$ok,'archive_version'=>11,'entries'=>count($actual),'index_kind'=>$index['index_type']??null,'checks'=>$results,'full_extraction'=>$full],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
