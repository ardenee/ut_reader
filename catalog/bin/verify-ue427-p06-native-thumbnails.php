<?php
/**
 * Compare Epic's compiled FArchive thumbnail-table operator<< output with
 * UnrealDB's UE4.27 table decoding across the genuine installed corpus.
 */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$log=$argv[2]??'C:/Temp/ue427-p06-thumbnail-native.log';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$raw=@file_get_contents($log);
if(!is_array($paths)||!is_string($raw))throw new RuntimeException('Missing real UE4 corpus/native log');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$countRows=[];$totals=[];$records=[];$issues=[];
foreach(explode("\n",$raw) as $line){
 foreach(['UE427_NATIVE_P06_THUMB_COUNT|'=>'count','UE427_NATIVE_P06_THUMB_TOTAL|'=>'total',
          'UE427_NATIVE_P06_THUMB|'=>'record'] as $marker=>$kind){
  $at=strpos($line,$marker);if($at===false)continue;
  $values=explode('|',trim(substr($line,$at+strlen($marker))));
  $length=['count'=>3,'total'=>2,'record'=>6][$kind];
  if(count($values)!==$length){$issues[]=['invalid_native_'.$kind=>$values];break;}
  $i=(int)$values[0];
  if($kind==='count')$countRows[$i]=[(int)$values[1],(int)$values[2]];
  elseif($kind==='total')$totals[$i]=(int)$values[1];
  else $records[$i][(int)$values[1]]=[
   'offset'=>(int)$values[2],'class'=>strtoupper($values[3]),
   'path'=>strtoupper($values[4]),'fileOffset'=>(int)$values[5],
  ];
  break;
 }
}
$positive=0;$entries=0;$fields=0;$negative=0;
foreach($paths as $i=>$path){
 $reader=new UnrealPackageReader4($path);$h=$reader->getHeader();
 if($reader->validatePackage()!==[]){if(count($issues)<20)$issues[]=['file'=>$i,'readerIssues'=>$reader->validatePackage()];continue;}
 $offset=(int)($h['thumbnailTableOffset']??0);
 $expectedCount=$offset>0?-1:0;
 if(($countRows[$i]??null)!==[$offset,$expectedCount]){
  if(count($issues)<20)$issues[]=['file'=>$i,'wrongNativeSummary'=>$countRows[$i]??null];continue;
 }
 $parsed=$reader->getThumbnailIndex();
 if($offset<=0){
  $negative++;
  if($parsed!==[]||isset($totals[$i])||isset($records[$i]))$issues[]=['file'=>$i,'unexpectedThumbnailData'=>true];
  continue;
 }
 $positive++;$entries+=count($parsed);
 if(($totals[$i]??null)!==count($parsed) || count($records[$i]??[])!==count($parsed)){
  if(count($issues)<20)$issues[]=['file'=>$i,'recordCountMismatch'=>[$totals[$i]??null,count($parsed),count($records[$i]??[])]];continue;
 }
 foreach($parsed as $j=>$entry){
  $actual=['offset'=>$entry['offset'],'class'=>strtoupper(bin2hex($entry['className'])),
   'path'=>strtoupper(bin2hex($entry['objectPath'])),'fileOffset'=>$entry['fileOffset']];
  $fields+=4;
  if(($records[$i][$j]??null)!==$actual && count($issues)<20)$issues[]=['file'=>$i,'thumbnail'=>$j,'epic'=>$records[$i][$j]??null,'reader'=>$actual];
 }
}
$ok=$issues===[] && count($countRows)===count($paths) && count($paths)===1749 &&
 $positive===1724 && $negative===25 && $entries===1729 && $fields===6916;
echo json_encode(['ok'=>$ok,'packages'=>count($paths),'indexed_packages'=>$positive,
 'without_thumbnail_table'=>$negative,'thumbnail_entries'=>$entries,
 'native_entry_fields_compared'=>$fields,'mismatch_count'=>count($issues),
 'mismatches'=>array_slice($issues,0,20)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
