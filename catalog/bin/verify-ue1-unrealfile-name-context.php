<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileObjectBaseReader as Reader;
$checks=[];$failed=[];
$check=static function(string $name,bool $pass)use(&$checks,&$failed):void{$checks[$name]=$pass;if(!$pass){$failed[]=$name;}};
$names='';
foreach([['Class',C::RF_LOAD_FOR_EDIT],['Thing',C::RF_LOAD_FOR_CLIENT],['ServerOnly',C::RF_LOAD_FOR_SERVER]] as [$name,$flags]){$names.=$name."\0".pack('V',$flags);}
$offset=56+strlen($names);
$bytes=pack('a32V6',"Unrealfile\x1A",23,2,3,56,$offset,0).$names.pack('vvVV',2,1,0,0).pack('vvVV',0,3,0,0);
$path=tempnam(sys_get_temp_dir(),'ue1context_');
if(!is_string($path)){throw new RuntimeException('Fixture allocation failed');}
try {
    file_put_contents($path,$bytes);
    $raw=Reader::read($path);
    $check('no_implicit_context',!isset($raw['effective_names']) && !isset($raw['object_bases'][0]['effective_name']) && ($raw['object_bases'][0]['name']??null)==='Thing');
    $cases=[
        ['editor',C::RF_LOAD_FOR_EDIT,'None','Class','None'],
        ['client',C::RF_LOAD_FOR_CLIENT,'Thing','None','None'],
        ['server',C::RF_LOAD_FOR_SERVER,'None','None','ServerOnly'],
        ['all_contexts',C::ALL_LOAD_CONTEXTS,'Thing','Class','ServerOnly'],
    ];
    foreach($cases as [$label,$flag,$name,$class,$other]){
        $data=Reader::read($path,$flag);
        $check($label.'_effective',($data['object_bases'][0]['effective_name']??null)===$name && ($data['object_bases'][0]['effective_class_name']??null)===$class && ($data['object_bases'][1]['effective_class_name']??null)===$other);
        $check($label.'_none_sentinel',($data['object_bases'][1]['effective_name']??null)==='None');
        $check($label.'_raw_preserved',($data['object_bases'][0]['name']??null)==='Thing' && ($data['object_bases'][0]['class_name']??null)==='Class' && ($data['resolved_name_context_flags']??null)===$flag);
    }
    foreach([0,0x80000000] as $invalid){
        try{Reader::read($path,$invalid);$rejected=false;}
        catch(RuntimeException){$rejected=true;}
        $check('invalid_context_'.$invalid,$rejected);
    }
} finally {@unlink($path);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
