#!/usr/bin/env php
<?php
/**
 * Real MySQL/PDO staging integration using session TEMPORARY tables only.
 * Nothing is inserted into permanent ue_files or ue_unverified_metadata.
 */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(2);}
require dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataStore;
$checks=[];$failed=[];
$check=static function(string $n,bool $ok)use(&$checks,&$failed):void{
    $checks[$n]=$ok;if(!$ok){$failed[]=$n;}
};
$app=catalog_bootstrap(false);
$db=$app->db;
if($db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'){
    throw new RuntimeException('Isolated staging test requires the native MySQL PDO driver.');
}
$temp=[];
$files=[];
try{
    foreach(['ue_files','ue_unverified_metadata'] as $table){
        $db->exec('CREATE TEMPORARY TABLE '.$table.' AS SELECT * FROM '.$table.' WHERE 1=0');
        $temp[]=$table;
    }
    // CREATE TEMPORARY ... AS SELECT retains column types but not indexes or
    // AUTO_INCREMENT. Restore only the keys required by the real upsert path.
    $db->exec('ALTER TABLE ue_files MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY');
    $db->exec('ALTER TABLE ue_unverified_metadata ADD PRIMARY KEY(file_id)');
    $test=static function(string $type) : string {
        if($type==='resource'){
            $tag="Unreal Resource\x1A";
            $prefix=$tag."\0";
            $names=pack('a32V',"Source\xC3\xA9",0x42);
            $nameOffset=strlen($prefix);
            $importOffset=$nameOffset+strlen($names);
            $imports=pack('a32VVV','ImportRes',3,2,76);
            $exportOffset=$importOffset+strlen($imports);
            $exports=pack('a32VVV','ExportRes',4,2,76);
            $dataOffset=$exportOffset+strlen($exports);
            $headerOffset=$dataOffset+4;
            $header=pack('V2a32V9',0,2,'ExportRes',0,0,4,0,0,$dataOffset,4,0x12345678,0);
            $trailer=pack('V9',9,1,1,1,$nameOffset,$importOffset,$exportOffset,$headerOffset,76)
                .str_repeat("\0",16).str_repeat("\0",80).pack('a32',$tag);
            return $prefix.$names.$imports.$exports.'DATA'.$header.'[SUMMARY]'.$trailer;
        }
        $names="Class\0".pack('V',42)."Thing\xC3\xA9\0".pack('V',43);
        $body='DATA';
        $objectsOffset=56+strlen($names)+strlen($body);
        $object=pack('vvVV',2,1,0,0);
        return pack('a32V6',"Unrealfile\x1A",23,1,2,56,$objectsOffset,0).$names.$body.$object;
    };
    $index=new CatalogUnverifiedStagingIndex($db,[]);
    $store=new CatalogUnverifiedMetadataStore($db);
    foreach(['resource','unrealfile'] as $type){
        $file=tempnam(sys_get_temp_dir(),'ue1_db_original_');
        if(!is_string($file)){throw new RuntimeException('Cannot allocate temporary fixture');}
        $files[]=$file;
        file_put_contents($file,$test($type));
        $queueName=$type.'.u';
        $result=$index->indexPath(0,$queueName,$file,$queueName,'Source-defined native UE1 metadata acceptance');
        $id=(int)$result['file_id'];
        $row=$db->query('SELECT * FROM ue_files WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
        $snapshot=$store->load($id,$type);
        $expectedVersion=$type==='resource'?9:23;
        $expectedImports=1;
        $expectedExports=$type==='resource'?1:0;
        $check($type.'_mysql_indexed_unverified',$id>0
            && ($row['scan_status']??null)==='unverified'
            && ($row['compatibility_status']??null)==='unverified'
            && ($row['detection_confidence']??null)==='medium');
        $check($type.'_exact_native_counts', (int)($row['name_count']??-1)===($type==='resource'?1:2)
            && (int)($row['import_count']??-1)===$expectedImports
            && (int)($row['export_count']??-1)===$expectedExports
            && (int)($row['detected_package_version']??-1)===$expectedVersion);
        $check($type.'_persistent_native_metadata',
            ($snapshot['source_format']??null)==='original-ue1-native-partial'
            && ($snapshot['native_metadata']['serialized_version']??null)===$expectedVersion
            && ($snapshot['native_metadata']['source_record_family']??null)===
                ($type==='resource'?'FResNameFileEntry':'UObjectBase'));
        $check($type.'_no_modern_identity_fakes',($snapshot['imports']??null)===[]
            && ($snapshot['exports']??null)===[]
            && ($snapshot['paths']['imports']??null)===[]
            && ($snapshot['paths']['exports']??null)===[]
            && ($row['package_guid']??null)===null
            && ($snapshot['verified']??true)===false);
        $check($type.'_nonmisleading_scan_notes',
            str_contains((string)($row['scan_notes']??''),'payload')
            && str_contains((string)($result['message']??''),'partial')
            && ($result['parse_error']??'')==='');
        $check($type.'_ansi_name_survives_mysql_storage',
            ($snapshot['names'][$type==='resource'?0:1]['name_text']??null)===
                ($type==='resource'?"Source\xC3\x83\xC2\xA9":"Thing\xC3\x83\xC2\xA9"));
        $check($type.'_mysql_compressed_payload_persists',
            ($type==='resource'
                ? ($snapshot['native_metadata']['resource_exports'][0]['name']??null)==='ExportRes'
                : ($snapshot['native_metadata']['object_bases'][0]['name']??null)==="Thing\xC3\x83\xC2\xA9")
            && is_array($snapshot['native_metadata']['summary']??null));
    }
    $check('session_temporary_rows_exact',
        (int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===2
        && (int)$db->query('SELECT COUNT(*) FROM ue_unverified_metadata')->fetchColumn()===2);
}finally{
    foreach(array_reverse($temp) as $table){$db->exec('DROP TEMPORARY TABLE IF EXISTS '.$table);}
    foreach($files as $file){@unlink($file);}
}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
