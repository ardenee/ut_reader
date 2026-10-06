<?php
/** Runs resumable game-level UEDB5 dependency Pass 2 from staged V5 state only. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class Uedb5GameDependencyPassService
{
    public const DEPENDENCY_POLICY = 'uedb5-dependency-pass-v9';

    private Uedb5MetadataReader $reader;
    private Uedb5DependencyRebuilder $rebuilder;
    private PdoUedb5PhysicalProviderSelector $selector;
    private PdoUedb5StagingRegistrationRepository $registration;
    private PdoUedb5DependencyProjectionPublisher $publisher;
    private PdoUedb5MigrationStatusRepository $statuses;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, private readonly array $config)
    {
        $storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
        if ($storage === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 dependency Pass 2.');
        }
        $this->reader = new Uedb5MetadataReader($storage);
        $writer = new Uedb5MetadataSnapshotWriter($storage);
        $this->rebuilder = new Uedb5DependencyRebuilder($this->reader, $writer);
        $this->selector = new PdoUedb5PhysicalProviderSelector($db, $storage);
        $this->registration = new PdoUedb5StagingRegistrationRepository($db, $storage);
        $this->publisher = new PdoUedb5DependencyProjectionPublisher($db);
        $this->statuses = new PdoUedb5MigrationStatusRepository($db);
    }

    /** @return array<string,mixed> */
    public function preflight(int $gameId): array
    {
        $game = $this->game($gameId);
        foreach ([
            'ue_files','ue_uedb5_files','ue_uedb5_provider_keys',
            'ue_uedb5_dependency_edges','ue_uedb5_dependency_packages',
            'ue_uedb5_migration_status','ue_invalid_file_identities',
        ] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Required UEDB5 Pass-2 table is missing: ' . $table);
            }
        }
        foreach (['dependency_policy','dependency_payload_sha256','dependency_completed_at'] as $column) {
            if (!$this->columnExists('ue_uedb5_migration_status', $column)) {
                throw new RuntimeException('Run catalog/bin/migrate.php migrate before UEDB5 Pass 2; missing status column: ' . $column);
            }
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) verified_count,'
            . 'SUM(CASE WHEN v.file_id IS NOT NULL THEN 1 ELSE 0 END) staged_count,'
            . 'SUM(CASE WHEN p.file_id IS NULL THEN 1 ELSE 0 END) missing_primary_provider_count '
            . 'FROM ue_files f '
            . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'LEFT JOIN ue_uedb5_provider_keys p ON p.file_id=f.id AND p.source_kind=1 AND p.source_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified"'
        );
        $statement->execute([$gameId]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $verified = (int)($counts['verified_count'] ?? 0);
        $staged = (int)($counts['staged_count'] ?? 0);
        $missingPrimary = (int)($counts['missing_primary_provider_count'] ?? 0);

        $completedStatement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" '
            . 'AND s.dependency_policy=? AND s.dependency_payload_sha256=v.payload_sha256'
        );
        $completedStatement->execute([$gameId, self::DEPENDENCY_POLICY]);
        $completed = (int)$completedStatement->fetchColumn();
        $invalidStatement = $this->db->prepare(
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'JOIN ue_invalid_file_identities bad ON bad.file_size=f.file_size '
            . 'AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1) '
            . 'WHERE f.game_id=? AND f.scan_status="verified"'
        );
        $invalidStatement->execute([$gameId]);
        $invalidStaged = (int)$invalidStatement->fetchColumn();
        $ready = $verified > 0
            && $staged === $verified
            && $missingPrimary === 0
            && $invalidStaged === 0;

        return [
            'game' => $game,
            'verified_count' => $verified,
            'staged_count' => $staged,
            'missing_staged_count' => max(0, $verified - $staged),
            'missing_primary_provider_count' => $missingPrimary,
            'invalid_staged_count' => $invalidStaged,
            'dependency_complete_count' => $completed,
            'dependency_remaining_count' => max(0, $staged - $completed),
            'dependency_policy' => self::DEPENDENCY_POLICY,
            'pass2_ready' => $ready,
            'uses_uedb4_metadata' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function run(
        int $gameId,
        bool $apply,
        int $limit = 500,
        bool $continuous = false,
        int $progressEvery = 50,
        ?callable $emit = null,
        int $workerCount = 1,
        int $workerIndex = 0,
        bool $skipPreflight = false,
        bool $force = false
    ): array {
        if ($emit) {
            $emit(['status'=>'worker_boot','worker_count'=>$workerCount,'worker_index'=>$workerIndex]);
        }
        $preflight = $skipPreflight
            ? ['game'=>$this->game($gameId),'pass2_ready'=>true,'worker_preflight_skipped'=>true]
            : $this->preflight($gameId);
        if (empty($preflight['pass2_ready'])) {
            throw new RuntimeException(
                'UEDB5 dependency Pass 2 requires complete staged V5/provider coverage and zero invalid staged identities.'
            );
        }
        $game = (array)$preflight['game'];
        $limit = max(1, min(5000, $limit));
        $progressEvery = max(1, $progressEvery);
        $workerCount = max(1, min(8, $workerCount));
        if ($workerIndex < 0 || $workerIndex >= $workerCount) {
            throw new RuntimeException('UEDB5 dependency worker index is outside the configured worker count.');
        }
        $options = $this->resolverOptions($gameId);
        $firstRemainingId = $this->firstRemainingFileId($gameId, $workerCount, $workerIndex, $force);
        $cursor = $firstRemainingId !== null ? max(0, $firstRemainingId - 1) : 0;
        $processed = $succeeded = $failed = 0;
        $failures = [];
        if ($emit) {
            $emit([
                'status'=>'worker_start',
                'worker_count'=>$workerCount,
                'worker_index'=>$workerIndex,
                'first_remaining_file_id'=>$firstRemainingId,
                'force'=>$force,
            ]);
        }
        do {
            $rows = $this->batch($gameId, $cursor, $limit, $workerCount, $workerIndex, $force);
            if ($rows === []) { break; }
            foreach ($rows as $row) {
                $fileId = (int)($row['file_id'] ?? 0);
                $cursor = $fileId;
                $processed++;
                try {
                    $result = $this->processFile($gameId, $fileId, $apply, $options);
                    $succeeded++;
                    if ($emit && ($processed % $progressEvery === 0 || !$continuous)) {
                        $emit(['status'=>'ok','processed'=>$processed,'file_id'=>$fileId,'result'=>$result]);
                    }
                } catch (Throwable $error) {
                    $failed++;
                    if ($apply) {
                        try {
                            $this->statuses->markDependencyFailed(
                                $fileId,
                                $gameId,
                                'dependency_pass_failed',
                                $error->getMessage()
                            );
                        } catch (Throwable) {
                        }
                    }
                    if (count($failures) < 50) {
                        $failures[] = ['file_id'=>$fileId,'error'=>$error->getMessage()];
                    }
                    if ($emit) {
                        $emit(['status'=>'failed','processed'=>$processed,'file_id'=>$fileId,'error'=>$error->getMessage()]);
                    }
                }
            }
        } while ($continuous && count($rows) === $limit);

        return [
            'apply'=>$apply,'game'=>$game,'preflight'=>$preflight,
            'processed'=>$processed,'succeeded'=>$succeeded,'failed'=>$failed,
            'last_file_id'=>$cursor,'worker_count'=>$workerCount,'worker_index'=>$workerIndex,
            'force'=>$force,'failures'=>$failures,
        ];
    }

    /** @return array<string,mixed> */
    public function runFile(int $gameId, int $fileId, bool $apply, bool $skipPreflight = false): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('Targeted UEDB5 dependency Pass 2 requires a positive file ID.');
        }
        $preflight = $skipPreflight
            ? ['game'=>$this->game($gameId),'pass2_ready'=>true,'worker_preflight_skipped'=>true]
            : $this->preflight($gameId);
        if (empty($preflight['pass2_ready'])) {
            throw new RuntimeException(
                'Targeted UEDB5 dependency Pass 2 requires complete staged V5/provider coverage and zero invalid staged identities.'
            );
        }
        $statement = $this->db->prepare(
            'SELECT f.id FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'WHERE f.id=? AND f.game_id=? AND f.scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId, $gameId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Targeted UEDB5 dependency file is not a verified staged file in the requested game.');
        }
        try {
            $result = $this->processFile($gameId, $fileId, $apply, $this->resolverOptions($gameId));
        } catch (Throwable $error) {
            if ($apply) {
                try {
                    $this->statuses->markDependencyFailed(
                        $fileId, $gameId, 'dependency_pass_failed', $error->getMessage()
                    );
                } catch (Throwable) {
                }
            }
            throw $error;
        }
        return [
            'apply'=>$apply,
            'game'=>(array)$preflight['game'],
            'preflight'=>$preflight,
            'file_id'=>$fileId,
            'result'=>$result,
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function processFile(int $gameId, int $fileId, bool $apply, array $options): array
    {
        $selectedProviders = $this->selector->select($gameId, $fileId, $options);
        $selectedProviderFileIds = array_values(array_map(
            static fn(array $row): int => (int)$row['file_id'],
            array_filter($selectedProviders, static fn(array $row): bool => (int)($row['file_id'] ?? 0) > 0)
        ));
        $ambiguousProviderCount = count(array_filter(
            $selectedProviders,
            static fn(array $row): bool => (string)($row['selection_status'] ?? '') === 'ambiguous'
        ));
        if (!$apply) {
            $snapshot = $this->reader->snapshot($gameId, $fileId);
            return [
                'package_family'=>(string)($snapshot['package_family'] ?? ''),
                'provider_count'=>count($selectedProviderFileIds),
                'ambiguous_provider_count'=>$ambiguousProviderCount,
                'selected_provider_file_ids'=>$selectedProviderFileIds,
            ];
        }

        $rebuilt = $this->rebuilder->rebuild($gameId, $fileId, $selectedProviders, $options);
        $registration = $this->registration->refreshExisting($gameId, $fileId);
        $this->reader->clearCache($gameId, $fileId);
        $snapshot = $this->reader->snapshot($gameId, $fileId);
        $projection = $this->publisher->publish($snapshot);
        $payloadSha = (string)($registration['payload_sha256'] ?? '');
        $this->statuses->markDependencySucceeded(
            $fileId,
            $gameId,
            $payloadSha,
            self::DEPENDENCY_POLICY
        );
        return [
            'provider_count'=>count($selectedProviderFileIds),
            'ambiguous_provider_count'=>$ambiguousProviderCount,
            'selected_provider_file_ids'=>$selectedProviderFileIds,
            'dependency_count'=>(int)($rebuilt['dependency_count'] ?? 0),
            'dependency_outcomes'=>(array)($rebuilt['dependency_outcomes'] ?? []),
            'dependency_schema'=>(string)($rebuilt['dependency_schema'] ?? ''),
            'projection'=>$projection,
            'payload_sha256_hex'=>strtoupper(bin2hex($payloadSha)),
        ];
    }

    /** @return array{common_packages:list<string>} */
    private function resolverOptions(int $gameId): array
    {
        $common = array_values(array_filter(
            array_map(static fn(mixed $value): string => trim((string)$value), (array)($this->config['common_packages'] ?? [])),
            static fn(string $value): bool => $value !== ''
        ));
        return ['common_packages'=>$common];
    }

    private function gameEngineKey(int $gameId): string
    {
        $statement = $this->db->prepare(
            'SELECT UPPER(COALESCE(p.engine_key,"")) FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=? LIMIT 1'
        );
        $statement->execute([$gameId]);
        return strtoupper(trim((string)($statement->fetchColumn() ?: '')));
    }

    private function firstRemainingFileId(int $gameId, int $workerCount, int $workerIndex, bool $force): ?int
    {
        $sql = 'SELECT MIN(f.id) FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND MOD(f.id,?)=?';
        $args = [$gameId,$workerCount,$workerIndex];
        if(!$force){
            $sql .= ' AND (s.dependency_policy IS NULL OR s.dependency_policy<>? '
                . 'OR s.dependency_payload_sha256 IS NULL OR s.dependency_payload_sha256<>v.payload_sha256)';
            $args[] = self::DEPENDENCY_POLICY;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int)$value;
    }

    /** @return list<array<string,mixed>> */
    private function batch(
        int $gameId,
        int $afterId,
        int $limit,
        int $workerCount,
        int $workerIndex,
        bool $force
    ): array {
        $sql = 'SELECT f.id file_id,v.payload_sha256 FROM ue_files f '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id '
            . 'LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.id>? AND MOD(f.id,?)=?';
        $args = [$gameId,$afterId,$workerCount,$workerIndex];
        if(!$force){
            $sql .= ' AND (s.dependency_policy IS NULL OR s.dependency_policy<>? '
                . 'OR s.dependency_payload_sha256 IS NULL OR s.dependency_payload_sha256<>v.payload_sha256)';
            $args[] = self::DEPENDENCY_POLICY;
        }
        $sql .= ' ORDER BY f.id LIMIT ' . max(1, min(5000, $limit));
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string,mixed> */
    private function game(int $gameId): array
    {
        $statement = $this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE id=? LIMIT 1');
        $statement->execute([$gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unknown game_id: ' . $gameId);
        }
        $row['source_key'] = Uedb5GameSourceRegistry::sourceKey($gameId);
        return $row;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
        );
        $statement->execute([$table,$column]);
        return (int)$statement->fetchColumn() > 0;
    }
}
