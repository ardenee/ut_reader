<?php
declare(strict_types=1);

// Focused Full Sync job-handler contract. Fake maintenance service injects one
// reader incompatibility without touching production DB, UEDB5 or source files.
namespace UnrealDb\Catalog\Infrastructure\Maintenance {
    use UnrealDb\Catalog\Infrastructure\Import\CatalogInvalidPackageException;
    final class CatalogFileMaintenanceActionService {
        public static bool $reject = true;
        public function __construct(mixed ...$args) {}
        public function execute(string $operation, array $file): array {
            if ($operation !== 'sync_reimport' || (int)($file['file_id'] ?? 0) !== 42) {
                throw new \RuntimeException('Incorrect file-operation path.');
            }
            if (self::$reject) {
                throw new CatalogInvalidPackageException('UE2 reader rejected archive', 'ue2_invalid_index');
            }
            return ['status'=>'reimported'];
        }
    }
}
namespace UnrealDb\Catalog\Infrastructure\Telemetry {
    final class CatalogSystemErrorRecorder {
        public static array $records = [];
        public static function record(array $row): void { self::$records[] = $row; }
    }
}
namespace UnrealDb\Catalog\Application\Jobs {
    final class JobExecutionContext {
        public array $checkpoints = [];
        public function checkpoint(array $progress=[]): void { $this->checkpoints[] = $progress; }
        public function heartbeatIfDue(array $progress=[]): void {}
    }
}
namespace {
    $root=dirname(__DIR__,2);
    require_once $root.'/catalog/src/Domain/Jobs/JobType.php';
    require_once $root.'/catalog/src/Domain/Jobs/ClaimedJob.php';
    require_once $root.'/catalog/src/Application/Jobs/JobHandler.php';
    require_once $root.'/catalog/src/Infrastructure/Import/CatalogInvalidPackageException.php';
    require_once $root.'/catalog/src/Infrastructure/Jobs/CatalogFullSyncUnitJobHandler.php';
    $db=new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE ue_files(id INTEGER, game_id INTEGER, package_name TEXT, original_name TEXT, md5 TEXT, sha1 TEXT, package_guid TEXT, relative_path TEXT, source_relative_path TEXT, file_size INTEGER, package_version INTEGER, licensee_version INTEGER, scan_status TEXT)');
    $db->exec("INSERT INTO ue_files VALUES(42,2,'Example','Example.utx','abc','def','', 'source/Example.utx','Example.utx',1024,126,0,'verified')");
    $job=new \UnrealDb\Catalog\Domain\Jobs\ClaimedJob(
        901,'catalog',\UnrealDb\Catalog\Domain\Jobs\JobType::FULL_SYNC_FILE,
        ['game_id'=>2,'file_id'=>42], 'fixture-lease',1,3,new \DateTimeImmutable('+1 hour')
    );
    $handler=new \UnrealDb\Catalog\Infrastructure\Jobs\CatalogFullSyncUnitJobHandler($db,[]);
    $bad=new \UnrealDb\Catalog\Application\Jobs\JobExecutionContext();
    $error='';
    try {
        $handler->handle($job,$bad);
    } catch (\RuntimeException $ex) {
        $error=$ex->getMessage();
    }
    $record=\UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder::$records[0]??[];
    $blocked=str_contains($error,'cannot regenerate UEDB5 metadata')
        && (($record['severity']??'')==='error')
        && (($record['context']['disposition']??'')==='blocked_reader_incompatible')
        && !in_array('complete',array_column($bad->checkpoints,'stage'),true)
        && ((int)$db->query('SELECT COUNT(*) FROM ue_files WHERE id=42 AND scan_status="verified"')->fetchColumn()===1);
    \UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService::$reject=false;
    $good=new \UnrealDb\Catalog\Application\Jobs\JobExecutionContext();
    $success=$handler->handle($job,$good);
    $accepted=($success['status']??'')==='reimported'
        && in_array('complete',array_column($good->checkpoints,'stage'),true);
    $pass=$blocked && $accepted;
    echo json_encode(['pass'=>$pass,'source_incompatible_child_must_fail'=>$blocked,
        'source_preserved'=>true,'successful_reimport_child_completes'=>$accepted,
        'game_id'=>2,'source_file_id'=>42,'production_db_or_storage_touched'=>false],
        JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
    exit($pass?0:2);
}
