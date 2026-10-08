<?php
/** Strict read-only gate for an atomic UEDB5 production cutover. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class Uedb5CutoverReadinessVerifier
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly string $catalogRoot
    ) {}

    /** @return array{checks:list<array<string,mixed>>,failures:list<string>} */
    public static function sourceChecks(string $catalogRoot): array
    {
        $checks=[];$failures=[];
        $record=static function(string $name,bool $ok,string $detail='')use(&$checks,&$failures):void{
            $checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];
            if(!$ok){$failures[]=$name.($detail!==''?': '.$detail:'');}
        };
        $runtimeCandidates=[
            'src/Infrastructure/Metadata/Uedb5MetadataReader.php',
            'src/Infrastructure/Metadata/Uedb5RuntimeMetadataReader.php',
            'src/Infrastructure/Metadata/Uedb5VerifiedFilePublisher.php',
            'src/Infrastructure/Metadata/VerifiedCompactMetadataHealth.php',
            'src/Infrastructure/Metadata/CatalogCompactDependencyReadService.php',
            'src/Infrastructure/Persistence/PdoCatalogDependencyRebuilder.php',
        ];
        $withoutComments=static function(string $source):string{
            $out='';
            foreach(token_get_all($source) as $token){
                if(is_array($token)&&in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)){continue;}
                $out.=is_array($token)?$token[1]:$token;
            }
            return $out;
        };
        $offenders=[];$missing=[];
        foreach($runtimeCandidates as $relative){
            $path=$catalogRoot.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            $source=@file_get_contents($path);
            if(!is_string($source)){$missing[]=$relative;continue;}
            $code=$withoutComments($source);
            if(preg_match('/BlockedCompressedMetadataReader|BlockedCompressedMetadataContainer|\.uedb4\b|ue_file_metadata|format_version\s*=\s*4\b/i',$code)===1){
                $offenders[]=$relative;
            }
        }
        $record('v5_runtime_candidate_files_exist',$missing===[],$missing===[]?'all present':implode(', ',$missing));
        $record(
            'v5_runtime_has_no_v4_fallback_literals',
            $offenders===[],
            $offenders===[]?'none':implode(', ',$offenders)
        );

        $readerPath=$catalogRoot.'/src/Infrastructure/Metadata/Uedb5MetadataReader.php';
        $reader=(string)@file_get_contents($readerPath);
        $readerCode=$withoutComments($reader);
        $record(
            'v5_reader_is_format5_only',
            str_contains($readerCode,'Uedb5MetadataContainer::path')
                && !str_contains($readerCode,'BlockedCompressedMetadataReader')
                && !str_contains($readerCode,'BlockedCompressedMetadataContainer')
                && !str_contains($readerCode,'.uedb4')
                && !str_contains($readerCode,'ue_file_metadata'),
            'candidate production reader must never consult V4 metadata or files'
        );

        $validator=(string)@file_get_contents($catalogRoot.'/src/Infrastructure/Metadata/Uedb5MigrationValidator.php');
        $validatorCode=$withoutComments($validator);
        $record(
            'cutover_validator_never_reads_live_v4',
            !str_contains($validatorCode,'BlockedCompressedMetadataReader')
                && !str_contains($validatorCode,'BlockedCompressedMetadataContainer')
                && !str_contains($validatorCode,'.uedb4')
                && !str_contains($validatorCode,'ue_file_metadata'),
            'final V5 validation must not prove V5 correctness from V4 state'
        );
        return ['checks'=>$checks,'failures'=>$failures];
    }

    /** @return array<string,mixed> */
    public function verifyDatabase(
        int $progressEvery=500,
        int $maxFailures=50,
        ?callable $progress=null
    ): array {
        $progressEvery=max(1,$progressEvery);
        $maxFailures=max(1,min(500,$maxFailures));
        $checks=[];$failures=[];
        $record=static function(string $name,bool $ok,string $detail='')use(&$checks,&$failures):void{
            $checks[]=['check'=>$name,'ok'=>$ok,'detail'=>$detail];
            if(!$ok){$failures[]=$name.($detail!==''?': '.$detail:'');}
        };
        $requiredTables=[
            'ue_files','ue_games','ue_invalid_file_identities',
            'ue_uedb5_files','ue_uedb5_provider_keys','ue_uedb5_search_keys',
            'ue_uedb5_name_candidates','ue_uedb5_object_candidates','ue_uedb5_dependency_edges',
            'ue_uedb5_dependency_packages','ue_uedb5_migration_status',
        ];
        $missingTables=[];
        foreach($requiredTables as $table){if(!$this->tableExists($table)){$missingTables[]=$table;}}
        $record('required_v5_cutover_tables_exist',$missingTables===[],$missingTables===[]?'all present':implode(', ',$missingTables));
        if($missingTables!==[]){
            return ['checks'=>$checks,'failures'=>$failures,'cutover_ready'=>false,'deep_validation'=>['skipped'=>true,'reason'=>'missing_tables']];
        }

        $verified=$this->count('SELECT COUNT(*) FROM ue_files WHERE scan_status="verified"');
        $record('verified_catalogue_is_nonempty',$verified>0,'verified='.$verified);
        $missingV5=$this->count(
            'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'WHERE f.scan_status="verified" AND v.file_id IS NULL'
        );
        $record('every_verified_file_has_staged_v5',$missingV5===0,'missing_v5='.$missingV5);

        $nonFormat5=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'WHERE f.scan_status="verified" AND v.format_version<>5'
        );
        $record('every_verified_v5_registration_is_format5',$nonFormat5===0,'non_format5='.$nonFormat5);

        $stagedNonVerified=$this->count(
            'SELECT COUNT(*) FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id WHERE f.scan_status<>"verified"'
        );
        $record('v5_staging_excludes_nonverified_files',$stagedNonVerified===0,'nonverified_staged='.$stagedNonVerified);

        $statusMissing=$this->count(
            'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            .'WHERE f.scan_status="verified" AND s.file_id IS NULL'
        );
        $record('every_verified_file_has_migration_status',$statusMissing===0,'missing_status='.$statusMissing);
        $notValidated=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            .'WHERE f.scan_status="verified" AND s.status<>"validated"'
        );
        $record('every_verified_file_is_step8_validated',$notValidated===0,'not_validated='.$notValidated);

        $stalePolicy=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            .'WHERE f.scan_status="verified" AND (s.validator_policy IS NULL OR s.validator_policy<>?)',
            [Uedb5MigrationStatus::VALIDATOR_POLICY]
        );
        $record('validated_status_uses_current_policy',$stalePolicy===0,'stale_validator_policy='.$stalePolicy);

        $staleHash=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            .'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id WHERE f.scan_status="verified" '
            .'AND (v.file_id IS NULL OR s.validated_payload_sha256 IS NULL OR s.validated_payload_sha256<>v.payload_sha256)'
        );
        $record('validated_status_matches_current_v5_payload',$staleHash===0,'stale_validated_hash='.$staleHash);

        $emptyPolicy=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'WHERE f.scan_status="verified" AND (v.package_family="" OR v.source_policy="")'
        );
        $record('every_v5_registration_has_source_policy',$emptyPolicy===0,'empty_family_or_policy='.$emptyPolicy);
        $invalidStaged=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'JOIN ue_invalid_file_identities bad ON bad.file_size=f.file_size '
            .'AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1) '
            .'WHERE f.scan_status="verified"'
        );
        $record('invalid_file_identities_are_excluded_from_v5',$invalidStaged===0,'invalid_staged='.$invalidStaged);

        $missingProvider=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'LEFT JOIN ue_uedb5_provider_keys p ON p.file_id=f.id AND p.source_kind=1 AND p.source_id=f.id '
            .'WHERE f.scan_status="verified" AND p.file_id IS NULL'
        );
        $record('every_verified_v5_has_primary_provider_key',$missingProvider===0,'missing_primary_provider='.$missingProvider);

        $zenFamily=Uedb5ZenPackageReader::PACKAGE_FAMILY;
        $classicDependencyMismatch=$this->count(
            'SELECT COUNT(*) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'LEFT JOIN (SELECT file_id,COUNT(*) edge_count FROM ue_uedb5_dependency_edges '
            .'WHERE source_kind=? GROUP BY file_id) e ON e.file_id=f.id '
            .'WHERE f.scan_status="verified" AND v.package_family<>? '
            .'AND COALESCE(e.edge_count,0)<>f.import_count',
            [Uedb5SqlProjectionContract::DEP_SOURCE_IMPORT,$zenFamily]
        );
        $record('classic_dependency_edge_count_matches_import_count',$classicDependencyMismatch===0,'files_with_dependency_count_mismatch='.$classicDependencyMismatch);
        $factory=new Uedb5SourceSnapshotFactory($this->db,$this->config);
        $coverage=[];$unsupportedGames=[];$profileRejected=0;
        $games=$this->rows(
            'SELECT g.id,g.slug,COUNT(*) verified_count,MIN(f.package_version) min_version,MAX(f.package_version) max_version '
            .'FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.scan_status="verified" '
            .'GROUP BY g.id,g.slug ORDER BY g.id'
        );
        foreach($games as $game){
            $slug=(string)$game['slug'];
            $gameId=(int)$game['id'];
            try{$contract=$factory->contractForGameId($gameId);$sourceKey=Uedb5GameSourceRegistry::sourceKey($gameId);}catch(Throwable $error){
                $unsupportedGames[]='game_id='.$gameId.' slug='.$slug;
                $coverage[]=['game_id'=>$gameId,'game'=>$slug,'verified_count'=>(int)$game['verified_count'],'supported'=>false,'error'=>$error->getMessage()];
                continue;
            }
            $outside=0;
            foreach($this->rows(
                'SELECT id,package_version,licensee_version FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY id',
                [$gameId]
            ) as $file){
                if(!$factory->profileAllowsCatalogRow($gameId,$file)){$outside++;}
            }
            $profileRejected+=$outside;
            $coverage[]=['game_id'=>$gameId,'game'=>$slug,'source_key'=>$sourceKey,'verified_count'=>(int)$game['verified_count'],'supported'=>true,
                'engine_key'=>(string)$contract['engine_key'],'profile_version_range'=>[$contract['min_version'],$contract['max_version']],
                'version_gate'=>'game_profile',
                'catalogue_version_range'=>[$game['min_version']!==null?(int)$game['min_version']:null,$game['max_version']!==null?(int)$game['max_version']:null],
                'outside_game_profile'=>$outside,
                'outside_source_contract'=>$outside];
        }
        $record('every_verified_game_has_v5_source_contract',$unsupportedGames===[],$unsupportedGames===[]?'all supported':implode(', ',$unsupportedGames));
        $record('every_verified_file_is_allowed_by_game_profile',$profileRejected===0,'outside_game_profile='.$profileRejected);
        $policyCoverage=$this->rows(
            'SELECT g.slug,v.package_family,v.source_policy,COUNT(*) file_count '
            .'FROM ue_files f JOIN ue_games g ON g.id=f.game_id JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'WHERE f.scan_status="verified" GROUP BY g.slug,v.package_family,v.source_policy '
            .'ORDER BY g.slug,v.package_family,v.source_policy'
        );
        foreach($policyCoverage as &$row){$row['file_count']=(int)$row['file_count'];}unset($row);

        $statusCounts=[];
        foreach($this->rows(
            'SELECT s.status,COUNT(*) file_count FROM ue_uedb5_migration_status s '
            .'JOIN ue_files f ON f.id=s.file_id WHERE f.scan_status="verified" GROUP BY s.status'
        ) as $row){$statusCounts[(string)$row['status']]=(int)$row['file_count'];}

        $liveV4=$this->tableExists('ue_file_metadata')
            ? $this->count(
                'SELECT COUNT(*) FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4 '
                .'WHERE f.scan_status="verified"'
            )
            : 0;
        $summary=[
            'verified_count'=>$verified,
            'live_v4_registration_count'=>$liveV4,
            'status_counts'=>$statusCounts,
            'engine_source_coverage'=>$coverage,
            'source_policy_coverage'=>$policyCoverage,
        ];

        if($failures!==[]){
            return ['checks'=>$checks,'failures'=>$failures,'summary'=>$summary,'cutover_ready'=>false,
                'deep_validation'=>['skipped'=>true,'reason'=>'global_cutover_blockers']];
        }
        $validator=new Uedb5MigrationValidator($this->db,$this->config);
        $statement=$this->db->query(
            'SELECT f.id FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            .'JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            .'WHERE f.scan_status="verified" AND v.format_version=5 AND s.status="validated" ORDER BY f.id'
        );
        $deepChecked=0;$deepFailed=0;$deepFailures=[];$failureCodes=[];
        while(($fileId=$statement->fetchColumn())!==false){
            $fileId=(int)$fileId;$deepChecked++;
            try{
                $result=$validator->validate($fileId);
                if(empty($result['ready'])){
                    throw new Uedb5ValidationException('dependency_not_ready','UEDB5 dependency results are not cutover-ready.');
                }
            }catch(Throwable $error){
                $deepFailed++;
                $code=$error instanceof Uedb5ValidationException?$error->reasonCode:'validator_exception';
                $failureCodes[$code]=($failureCodes[$code]??0)+1;
                if(count($deepFailures)<$maxFailures){$deepFailures[]=['file_id'=>$fileId,'code'=>$code,'error'=>$error->getMessage()];}
                if($deepFailed>=$maxFailures){break;}
            }
            if($progress!==null&&($deepChecked%$progressEvery===0||$deepChecked===$verified)){
                $progress(['status'=>'deep_validation_progress','checked'=>$deepChecked,'verified'=>$verified,'failed'=>$deepFailed,'file_id'=>$fileId]);
            }
        }
        ksort($failureCodes,SORT_STRING);
        $deepComplete=$deepChecked===$verified&&$deepFailed===0;
        $detail='checked='.$deepChecked.' verified='.$verified.' failed='.$deepFailed;
        $record('every_v5_container_exists_and_hash_matches_registration',$deepComplete,$detail);
        $record('dependency_rows_correspond_to_v5',$deepComplete,$detail);
        $record('projections_correspond_to_v5',$deepComplete,$detail);
        $record('engine_source_policy_coverage_is_deep_validated',$deepComplete,$detail);
        $record('no_verified_file_requires_v4_metadata',$deepComplete,$detail);
        $record('no_v4_only_migration_blockers_remain',$deepComplete,$detail);

        return [
            'checks'=>$checks,
            'failures'=>$failures,
            'summary'=>$summary,
            'cutover_ready'=>$failures===[],
            'deep_validation'=>[
                'skipped'=>false,
                'checked'=>$deepChecked,
                'failed'=>$deepFailed,
                'failure_codes'=>$failureCodes,
                'failure_examples'=>$deepFailures,
            ],
        ];
    }

    /** @param list<mixed> $params */
    private function count(string $sql,array $params=[]):int
    {
        $statement=$this->db->prepare($sql);$statement->execute($params);return(int)$statement->fetchColumn();
    }
    /** @return list<array<string,mixed>> */
    private function rows(string $sql,array $params=[]):array
    {
        $statement=$this->db->prepare($sql);$statement->execute($params);return $statement->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function tableExists(string $table):bool
    {
        $statement=$this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);return(int)$statement->fetchColumn()>0;
    }
}
