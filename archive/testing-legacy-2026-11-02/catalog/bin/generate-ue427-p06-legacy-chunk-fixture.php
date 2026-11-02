<?php
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$files=file('C:/Temp/ue427-all-assets.txt',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$files[0]=preg_replace('/^\xEF\xBB\xBF/','',$files[0]);
@mkdir('C:/Temp/ue427-p06-world-fixtures',0777,true);
foreach($files as $path){
 $r=new UnrealPackageReader4($path);$h=$r->getHeader();
 $v=$h['version']??0;
 if($v<278||$v>=326||$h['chunkIDs']!==[]||$r->validatePackage()!==[])continue;
 $orig=file_get_contents($path);
 $sequence=pack('V',($h['assetRegistryDataOffset']??0))
    .pack('P',($h['bulkDataStartOffset']??0))
    .pack('V',($h['worldTileInfoDataOffset']??0))
    .pack('V',0xFFFFFFFF);
 $pos=strpos(substr($orig,0,1000),$sequence);
 if($pos===false)continue;
 if(strpos(substr($orig,$pos+1,1000),$sequence)!==false)continue;
 $idPos=$pos+16;
 $data=substr_replace($orig,pack('V',7),$idPos,4);
 $out='C:/Temp/ue427-p06-world-fixtures/legacy-single-chunk.uasset';
 file_put_contents($out,$data);
 $x=new UnrealPackageReader4($out);
 echo json_encode(['ok'=>$x->validatePackage()===[]&&$x->getHeader()['chunkIDs']===[7],
 'source'=>$path,'version'=>$v,'chunk_id_offset'=>$idPos,'field_block_offset'=>$pos,
 'source_chunks'=>$h['chunkIDs'],'mutated_chunks'=>$x->getHeader()['chunkIDs'],
 'issues'=>$x->validatePackage(),'file'=>$out],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
 exit(0);
}
echo json_encode(['ok'=>false,'reason'=>'No genuine old UE4 source-shaped chunk-field specimen found']),PHP_EOL;
exit(2);
