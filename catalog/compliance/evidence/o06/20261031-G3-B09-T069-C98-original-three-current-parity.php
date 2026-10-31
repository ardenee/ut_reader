<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$files=[
'badname'=>'C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/G3C98DefaultsT069-C98-native-bad-name.u',
'badimport'=>'C:/Users/arden/source/repos/ut_reader/catalog/compliance/evidence/o06/20261031-G3-B08-T069-C98-original-super-invalid-import63.u',
'badexport'=>'C:/Users/arden/source/repos/ut_reader/catalog/compliance/evidence/o06/20261031-G3-B08-T069-C98-original-super-invalid-export63.u'
];
foreach($files as $kind=>$f){
 $p=new P($f);try{$r=C::inspect($f,$p,0,true,'epic-ue1');echo $kind.' ACCEPT '.json_encode(['status'=>$r['status'],'super_ref'=>$r['super_field_reference']??null,'archive_complete'=>$r['source_file_archive_complete']??null]).PHP_EOL;}
 catch(Throwable $e){echo $kind.' EXCEPTION '.$e->getMessage().PHP_EOL;}
}
