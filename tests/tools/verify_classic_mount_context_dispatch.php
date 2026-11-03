<?php
declare(strict_types=1);
/**
 * Verifies engine-owned classic mount context dispatch in the actual Pass-2
 * service without a database migration or original runtime mount simulation.
 * Synthetic stage records test *dispatch only*; genuine Epic file evidence
 * is separately kept in the existing UE1-UE5 contracts.
 */
require_once dirname(__DIR__, 2) . '/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;

$root=sys_get_temp_dir().'/unrealdb-five-engine-mount-dispatch-'.bin2hex(random_bytes(6));
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$pdo->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$cases=[
    ['UE1',3,'classic-linkerload','ue1.ut99.object-import.v1',100301,true],
    ['UE1',12,'classic-linkerload','ue1.unreal.object-import.v1',101201,true],
    ['UE2',2,'classic-linkerload','ue2.unreal2.object-import.v1',100201,true],
    ['UE2',4,'classic-linkerload','ue2.ut2003.object-import.v1',100401,true],
    ['UE2',5,'classic-linkerload','ue2.ut2004.object-import.v1',100501,true],
    ['UE2',5,'classic-linkerload','ue1.ut2004.compat.object-import.v1',100502,true],
    ['UE3',6,'classic-linkerload','ue3.ut3.object-import.v1',100601,true],
    ['UE4',7,'ue4-classic-package','ue4.ut4.object-import.v1',100701,true],
    ['UE5',14,'classic-linkerload','ue5.classic.object-import.v1',101401,true],
    ['UE5',14,'zen-iostore','ue5.zen.object-import.v1',101402,false],
    // A synthetic UE5 classic-looking file attached to a UE3 game must fail.
    ['UE3',6,'classic-linkerload','ue5.classic.object-import.v1',100602,false],
    // A synthetic UE1 compatibility file attached to UT99 must fail.
    ['UE1',3,'classic-linkerload','ue1.ut2004.compat.object-import.v1',100302,false],
];
$games=[];
foreach($cases as [$engine,$gameId]) {
    if(isset($games[$gameId]))continue;
    $games[$gameId]=true;
    $s=$pdo->prepare('INSERT INTO ue_game_profiles(id,engine_key,is_active) VALUES(?,?,1)');
    $s->execute([$gameId,$engine]);
    $s=$pdo->prepare('INSERT INTO ue_games(id,profile_id) VALUES(?,?)');
    $s->execute([$gameId,$gameId]);
}
$writer=new Uedb5MetadataSnapshotWriter($root);
$method=new ReflectionMethod(Uedb5GameDependencyPassService::class,'withClassicNativeMountEvidence');
$accepted=0;$rejected=0;
foreach($cases as [$engine,$gameId,$family,$schema,$fileId,$isValid]) {
    $writer->write([
        'file'=>['id'=>$fileId,'game_id'=>$gameId,'package_name'=>'SyntheticDispatchProbe','original_name'=>'synthetic.u'],
        'package_family'=>$family,
        'source_policy'=>'synthetic-source-type-guard-only',
        'section_schemas'=>[
            'summary'=>'synthetic.test.package-summary.v1',
            'names'=>'synthetic.test.names.v1',
            'imports'=>$schema,
            'exports'=>'synthetic.test.exports.v1'
        ],
        'sections'=>[
            'summary'=>[['package_version'=>0,'licensee_version'=>0]],
            'names'=>[],'imports'=>[],'exports'=>[]
        ],
    ]);
    $context=[
        'game_id'=>$gameId,'consumer_file_id'=>$fileId,
        'mount_order_authoritative'=>true,
        'mounted_file_ids_search_order'=>[$fileId],
        'evidence_source'=>'synthetic_dispatch_guard_not_native_evidence'
    ];
    $pass=new Uedb5GameDependencyPassService($pdo,[
        'storage_path'=>$root,
        'classic_runtime_context_by_game_and_file'=>[$gameId=>[$fileId=>$context]]
    ]);
    try {
        $out=$method->invoke($pass,$gameId,$fileId,[]);
        if(!$isValid||($out['classic_runtime_context']??null)!==$context) {
            throw new LogicException('Invalid source context was accepted or corrupted for '.$schema);
        }
        ++$accepted;
    } catch(RuntimeException $e) {
        if($isValid || !str_contains($e->getMessage(),'incompatible'))throw $e;
        ++$rejected;
    }
    // An authoritative context must not be transferred across file identity.
    if($isValid) {
        $wrong=$context;$wrong['consumer_file_id']=$fileId+1;
        $bad=new Uedb5GameDependencyPassService($pdo,[
            'storage_path'=>$root,
            'classic_runtime_context_by_game_and_file'=>[$gameId=>[$fileId=>$wrong]]
        ]);
        $denied=false;
        try{$method->invoke($bad,$gameId,$fileId,[]);}catch(RuntimeException $e){$denied=str_contains($e->getMessage(),'exact game and consumer');}
        if(!$denied)throw new RuntimeException('Wrong-file evidence was accepted for '.$schema);
    }
}
if($accepted!==9 || $rejected!==3)throw new RuntimeException('Unexpected engine-isolation counts');
echo json_encode([
    'result'=>'PASS','engine_families'=>5,
    'synthetic_dispatch_cases'=>count($cases),'valid_profiles'=>9,'rejected_wrong_families'=>3,
    'cross_file_context_rejection'=>true,'no_production_db_access'=>true,
    'native_runtime_mount_order_verified'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
