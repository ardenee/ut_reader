<?php
/** Final Step 8 validator for one staged UEDB5 file. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

final class Uedb5MigrationValidator
{
    private Uedb5MetadataReader $reader;
    private string $storageRoot;
    private Uedb5SourceSnapshotFactory $sourceSnapshots;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly PDO $db, array $config)
    {
        $this->storageRoot = rtrim((string)($config['storage_path'] ?? ''), "\\/");
        if ($this->storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 validation.');
        }
        $this->reader = new Uedb5MetadataReader($this->storageRoot);
        $this->sourceSnapshots = new Uedb5SourceSnapshotFactory($db, $config);
    }

    /** @return array<string,mixed> */
    public function validate(int $fileId, ?array $previouslyVerifiedSourceSnapshot = null, bool $trustStagedSource = false): array
    {
        $context = $this->context($fileId);
        $gameId = (int)$context['game_id'];
        $this->reader->clearCache($gameId, $fileId);
        $verified = $this->reader->verify($gameId, $fileId);
        $manifest = (array)($verified['manifest'] ?? []);
        $payloadSha = (string)($verified['payload_sha256'] ?? '');
        $registeredSha = (string)($context['payload_sha256'] ?? '');
        $this->require(
            strlen($payloadSha) === 32 && hash_equals($registeredSha, $payloadSha),
            'payload_hash_mismatch',
            'Verified UEDB5 payload SHA-256 does not match staged registration.'
        );
        $this->require((int)($manifest['format_version'] ?? 0) === 5, 'format_version', 'Container is not UEDB5.');
        $this->require((int)($manifest['file']['id'] ?? 0) === $fileId, 'file_identity', 'Manifest file ID mismatch.');
        $this->require((int)($manifest['file']['game_id'] ?? 0) === $gameId, 'game_identity', 'Manifest game ID mismatch.');
        $this->validateRegistrationManifest($verified, $manifest, $context);

        $snapshot = $this->reader->snapshot($gameId, $fileId);
        // A source snapshot supplied by the same-process Pass 1 was already
        // parsed after file-size/MD5/SHA1 verification. All V5/SQL checks below
        // remain mandatory. Normal callers still verify and reparse source.
        $sourceSnapshot = $previouslyVerifiedSourceSnapshot ?? ($trustStagedSource ? $snapshot : $this->validateSourceBytes($context));
        if (!$trustStagedSource || $previouslyVerifiedSourceSnapshot !== null) {
            $this->validateSourceSnapshot($snapshot, $sourceSnapshot);
        }
        $this->validatePackageIdentity($snapshot, $context);
        $counts = $this->validateCounts($snapshot, $context, $sourceSnapshot);
        $this->validateEngineSpecificFields($snapshot);
        $baseProjection = $this->validateBaseProjections($snapshot, $context);
        $dependency = $this->validateDependencies($snapshot, $context);

        return [
            'ready' => (bool)$dependency['ready'],
            'validator_policy' => Uedb5MigrationStatus::VALIDATOR_POLICY,
            'source_validation_mode' => $previouslyVerifiedSourceSnapshot !== null ? 'same_process_verified_source' : ($trustStagedSource ? 'trusted_staged_source' : 'fresh_verified_source'),
            'file_id' => $fileId,
            'game_id' => $gameId,
            'payload_sha256_hex' => strtoupper(bin2hex($payloadSha)),
            'package_family' => (string)($snapshot['package_family'] ?? ''),
            'source_policy' => (string)($snapshot['source_policy'] ?? ''),
            'counts' => $counts,
            'base_projection' => $baseProjection,
            'dependency' => $dependency,
        ];
    }

    /** @return array<string,mixed> */
    private function context(int $fileId): array
    {
        $statement = $this->db->prepare(
            'SELECT f.id,f.game_id,f.package_name,f.original_name,f.stored_name,f.file_size,f.md5,f.sha1,'
            . 'f.package_version,f.licensee_version,f.name_count,f.import_count,f.export_count,f.scan_status,'
            . 'g.slug,v.format_version,v.codec,v.compressed_size,v.uncompressed_size,v.payload_sha256,v.block_count,'
            . 'v.package_family,v.source_policy,v.package_name AS staged_package_name,v.section_counts_json,'
            . 'v.package_key_kind,v.package_key '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'JOIN ue_uedb5_files v ON v.file_id=f.id WHERE f.id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new Uedb5ValidationException('staging_registration_missing', 'No staged UEDB5 registration exists for file #' . $fileId . '.');
        }
        $this->require((string)$row['scan_status'] === 'verified', 'source_not_verified', 'Source catalogue file is no longer verified.');
        $this->require((int)$row['format_version'] === 5, 'staging_format_version', 'Staged SQL registration is not format 5.');
        return $row;
    }

    /** @param array<string,mixed> $verified @param array<string,mixed> $manifest @param array<string,mixed> $context */
    private function validateRegistrationManifest(array $verified, array $manifest, array $context): void
    {
        $this->require((int)$context['codec'] === Uedb5MetadataContainer::CODEC_BLOCK_GZIP, 'registration_codec_mismatch', 'Staged registration codec disagrees with UEDB5.');
        $this->require((int)$context['compressed_size'] === (int)$verified['compressed_size'], 'registration_size_mismatch', 'Staged compressed size disagrees with UEDB5.');
        $this->require((int)$context['block_count'] === (int)$verified['block_count'], 'registration_block_count_mismatch', 'Staged block count disagrees with UEDB5.');
        $this->require((string)$context['package_family'] === (string)($manifest['package_family'] ?? ''), 'registration_family_mismatch', 'Staged package family disagrees with UEDB5 manifest.');
        $this->require((string)$context['source_policy'] === (string)($manifest['source_policy'] ?? ''), 'registration_policy_mismatch', 'Staged source policy disagrees with UEDB5 manifest.');
        $uncompressed = max(0, (int)$verified['payload_start'] - Uedb5MetadataContainer::HEADER_LENGTH);
        foreach ((array)($manifest['sections'] ?? []) as $blocks) {
            foreach ((array)$blocks as $block) { $uncompressed += (int)((array)$block)['uncompressed_length']; }
        }
        $this->require((int)$context['uncompressed_size'] === $uncompressed, 'registration_uncompressed_size_mismatch', 'Staged uncompressed size disagrees with UEDB5.');
        $registeredCounts = json_decode((string)$context['section_counts_json'], true);
        $this->require(is_array($registeredCounts), 'registration_counts_invalid', 'Staged section-count registration is invalid JSON.');
        $manifestCounts = (array)($manifest['counts'] ?? []);
        ksort($registeredCounts, SORT_STRING); ksort($manifestCounts, SORT_STRING);
        $this->require($registeredCounts === $manifestCounts, 'registration_counts_mismatch', 'Staged section counts disagree with UEDB5 manifest.');
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function validateSourceBytes(array $context): array
    {
        $gameId = (int)$context['game_id'];
        $path = $this->storageRoot . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR
            . Uedb5GameSourceRegistry::storageKey($gameId) . DIRECTORY_SEPARATOR . 'verified' . DIRECTORY_SEPARATOR
            . (string)$context['stored_name'];
        $this->require(is_file($path), 'source_file_missing', 'Original verified source file is missing.');
        $size = filesize($path);
        $this->require($size !== false && (int)$size === (int)$context['file_size'], 'source_size_mismatch', 'Original source size changed.');
        $md5 = md5_file($path);
        $sha1 = sha1_file($path);
        $this->require(is_string($md5) && hash_equals(strtolower((string)$context['md5']), strtolower($md5)), 'source_md5_mismatch', 'Original source MD5 changed.');
        $this->require(is_string($sha1) && hash_equals(strtolower((string)$context['sha1']), strtolower($sha1)), 'source_sha1_mismatch', 'Original source SHA1 changed.');
        return $this->sourceSnapshots->buildForGameId($gameId, $path, $context);
    }

    /** @param array<string,mixed> $staged @param array<string,mixed> $source */
    private function validateSourceSnapshot(array $staged, array $source): void
    {
        $stagedBase = $this->sourceComparableSnapshot($staged);
        $sourceBase = $this->sourceComparableSnapshot($source);
        $this->require(
            $this->canonicalSnapshot($stagedBase) === $this->canonicalSnapshot($sourceBase),
            'source_snapshot_mismatch',
            'Staged UEDB5 source-shaped metadata differs from a fresh authoritative source parse.'
        );
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function sourceComparableSnapshot(array $snapshot): array
    {
        unset($snapshot['sections']['dependency_results'], $snapshot['section_schemas']['dependency_results']);
        if (isset($snapshot['sections']['summary'][0]) && is_array($snapshot['sections']['summary'][0])) {
            // uexp_path is a local filesystem locator derived by the reader, not
            // serialized package identity. Validation must not depend on which
            // equivalent path separator/style was used by the staging runtime.
            unset($snapshot['sections']['summary'][0]['uexp_path']);
        }
        return $snapshot;
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $context */
    private function validatePackageIdentity(array $snapshot, array $context): void
    {
        $file = (array)($snapshot['file'] ?? []);
        $this->require((int)($file['id'] ?? 0) === (int)$context['id'], 'snapshot_file_id', 'Snapshot file ID mismatch.');
        $this->require((int)($file['game_id'] ?? 0) === (int)$context['game_id'], 'snapshot_game_id', 'Snapshot game ID mismatch.');
        $this->require((string)($file['package_name'] ?? '') === (string)$context['package_name'], 'package_name_mismatch', 'UEDB5 package name differs from source catalogue identity.');
        $this->require((string)($file['original_name'] ?? '') === (string)$context['original_name'], 'original_name_mismatch', 'UEDB5 original filename differs from source catalogue identity.');
        $this->require((string)($context['staged_package_name'] ?? '') === (string)$context['package_name'], 'registered_package_name_mismatch', 'Staged package registration differs from source package identity.');

        $summary = $this->summaryRow($snapshot);
        $ue4Unversioned = (string)($snapshot['package_family'] ?? '') === Uedb5Ut4SnapshotBuilder::PACKAGE_FAMILY
            && !empty($summary['unversioned']);
        if (array_key_exists('package_version', $summary) && !$ue4Unversioned) {
            $this->require((int)$summary['package_version'] === (int)$context['package_version'], 'package_version_mismatch', 'UEDB5 package version differs from source catalogue identity.');
        }
        if (array_key_exists('licensee_version', $summary)) {
            $this->require((int)$summary['licensee_version'] === (int)$context['licensee_version'], 'licensee_version_mismatch', 'UEDB5 licensee version differs from source catalogue identity.');
        }
    }

    /** @return array<string,int> */
    private function validateCounts(array $snapshot, array $context, array $sourceSnapshot): array
    {
        $sections = (array)($snapshot['sections'] ?? []);
        $names = isset($sections['names']) ? count((array)$sections['names']) : count((array)($sections['name_map'] ?? []));
        $imports = count((array)($sections['imports'] ?? []));
        $exports = count((array)($sections['exports'] ?? []));
        $this->require($names === (int)$context['name_count'], 'name_count_mismatch', 'UEDB5 name count does not match source catalogue count.');
        $this->require($imports === (int)$context['import_count'], 'import_count_mismatch', 'UEDB5 import count does not match source catalogue count.');
        $this->require($exports === (int)$context['export_count'], 'export_count_mismatch', 'UEDB5 export count does not match source catalogue count.');
        $sourceSections = (array)($sourceSnapshot['sections'] ?? []);
        $sourceNames = isset($sourceSections['names']) ? count((array)$sourceSections['names']) : count((array)($sourceSections['name_map'] ?? []));
        $this->require($names === $sourceNames, 'source_name_count_mismatch', 'UEDB5 name count differs from fresh source parse.');
        $this->require($imports === count((array)($sourceSections['imports'] ?? [])), 'source_import_count_mismatch', 'UEDB5 import count differs from fresh source parse.');
        $this->require($exports === count((array)($sourceSections['exports'] ?? [])), 'source_export_count_mismatch', 'UEDB5 export count differs from fresh source parse.');
        return ['names'=>$names,'imports'=>$imports,'exports'=>$exports];
    }

    private function validateEngineSpecificFields(array $snapshot): void
    {
        $family = (string)($snapshot['package_family'] ?? '');
        $policy = (string)($snapshot['source_policy'] ?? '');
        $schemas = (array)($snapshot['section_schemas'] ?? []);
        $this->require($family !== '' && $policy !== '', 'source_policy_missing', 'UEDB5 package family/source policy is missing.');
        $this->require($schemas !== [], 'section_schemas_missing', 'UEDB5 section schemas are missing.');
        if ($family === Uedb5ZenPackageReader::PACKAGE_FAMILY) {
            $this->validateZenFields($snapshot);
            return;
        }
        if (str_starts_with($policy, 'ue5-')) {
            $this->validateUe5ClassicFields($snapshot);
            return;
        }
        if (str_starts_with($policy, 'ue4-')) {
            $this->validateUe4Fields($snapshot);
            return;
        }
        if (str_starts_with($policy, 'ue3-')) {
            $this->validateUe3Fields($snapshot);
            return;
        }
        $this->validateLegacyFields($snapshot);
    }

    private function validateLegacyFields(array $snapshot): void
    {
        $summary = $this->summaryRow($snapshot);
        $this->requireKeys($summary, ['serialized_tag','packed_file_version','package_version','licensee_version','summary_layout'], 'legacy summary');
        foreach ((array)($snapshot['sections']['imports'] ?? []) as $row) {
            $this->requireKeys((array)$row, ['serialized_offset','class_package','class_name','outer_index','object_name'], 'legacy import');
        }
        foreach ((array)($snapshot['sections']['exports'] ?? []) as $row) {
            $this->requireKeys((array)$row, ['serialized_offset','class_index','super_index','outer_index','object_name','object_flags','object_flags_serialized_width_bits','serial_size','serial_offset_present'], 'legacy export');
        }
    }

    private function validateUe3Fields(array $snapshot): void
    {
        $summary = $this->summaryRow($snapshot);
        $this->requireKeys($summary, ['serialized_tag','package_version','licensee_version','compression_flags','name_flags_serialized_width_bits','object_flags_serialized_width_bits'], 'UE3 summary');
        $this->require((int)$summary['object_flags_serialized_width_bits'] === 64, 'ue3_object_flags_width', 'UE3 ObjectFlags must retain 64-bit source width.');
        foreach ((array)($snapshot['sections']['exports'] ?? []) as $row) {
            $row = (array)$row;
            $this->requireKeys($row, ['serialized_offset','class_index','super_index','outer_index','object_name','object_flags','object_flags_serialized_width_bits','serial_size','serial_offset'], 'UE3 export');
            $this->require((int)$row['object_flags_serialized_width_bits'] === 64, 'ue3_export_flags_width', 'UE3 export ObjectFlags source width is not 64-bit.');
        }
    }

    private function validateUe4Fields(array $snapshot): void
    {
        $summary = $this->summaryRow($snapshot);
        $this->requireKeys($summary, ['serialized_tag','normalized_tag','byte_swapping','serialized_package_version','serialized_licensee_version','package_version','unversioned','parser_profile'], 'UE4 summary');
        if (!empty($summary['unversioned'])) {
            $profile = (array)$summary['parser_profile'];
            $assumedVersion = (int)($profile['assumed_unversioned_parser_version'] ?? 0);
            $this->require((int)$summary['serialized_package_version'] === 0, 'ue4_unversioned_serialized_version', 'UE4 unversioned package must preserve serialized package version 0.');
            $this->require((int)$summary['serialized_licensee_version'] === 0, 'ue4_unversioned_serialized_licensee', 'UE4 unversioned package must preserve serialized licensee version 0.');
            $this->require($assumedVersion > 0 && (int)$summary['package_version'] === $assumedVersion, 'ue4_unversioned_effective_version', 'UE4 unversioned effective package version must equal the retained parser-profile assumption.');
        }
        foreach ((array)($snapshot['sections']['imports'] ?? []) as $row) {
            $this->requireKeys((array)$row, ['serialized_offset','class_package','class_name','outer_index','object_name','package_name_present'], 'UE4 import');
        }
        foreach ((array)($snapshot['sections']['exports'] ?? []) as $row) {
            $row = (array)$row;
            $this->requireKeys($row, ['serialized_offset','template_index_present','object_name','object_flags','serial_range_serialized_width_bits','not_for_editor_game_present','is_asset_present','preload_present'], 'UE4 export');
            $this->require(in_array((int)$row['serial_range_serialized_width_bits'], [32,64], true), 'ue4_serial_width', 'UE4 export serial range width must be 32 or 64 bits.');
        }
    }

    private function validateUe5ClassicFields(array $snapshot): void
    {
        $summary = $this->summaryRow($snapshot);
        $this->requireKeys($summary, ['serialized_tag','serialized_file_version','effective_file_version','unversioned','parser_profile','counts'], 'UE5 classic summary');
        foreach ((array)($snapshot['sections']['imports'] ?? []) as $row) {
            $this->requireKeys((array)$row, ['serialized_offset','class_package','class_name','object_name','serialized_package_name_present','effective_package_name','b_import_optional_present'], 'UE5 classic import');
        }
        foreach ((array)($snapshot['sections']['exports'] ?? []) as $row) {
            $this->requireKeys((array)$row, ['serialized_offset','object_name','object_flags','object_flags_serialized_width_bits','serial_size_width_bits','serial_size','serial_offset','b_generate_public_hash_present','preload_dependency_range_present','script_serialization_offsets_present'], 'UE5 classic export');
        }
    }

    private function validateZenFields(array $snapshot): void
    {
        foreach (['container_provenance','package_summary','package_store','name_map','imports','exports','cell_imports','cell_exports','dependency_bundle_entries'] as $section) {
            $this->require(isset($snapshot['sections'][$section]) && is_array($snapshot['sections'][$section]), 'zen_section_missing', 'Zen snapshot is missing source section ' . $section . '.');
        }
        $summary = (array)($snapshot['sections']['package_summary'][0] ?? []);
        $this->requireKeys($summary, ['package_id','summary','header_bytes','exports_data_bytes'], 'Zen package summary');
        $this->require(preg_match('/^[0-9A-F]{16}$/', (string)$summary['package_id']) === 1, 'zen_package_id', 'Zen FPackageId is invalid.');
    }

    /** @return array<string,int> */
    private function validateBaseProjections(array $snapshot, array $context): array
    {
        $registration = [
            'file_id' => (int)$context['id'],
            'game_id' => (int)$context['game_id'],
            'package_key_kind' => (int)$context['package_key_kind'],
            'package_key' => (string)$context['package_key'],
        ];
        $expected = Uedb5SqlProjectionBuilder::build($snapshot, $registration);
        $fileId = (int)$context['id'];
        $expectedProvider = (new PdoUedb5ProviderKeyPublisher($this->db))->expectedRows($fileId);
        $actualProvider = $this->dbRows(
            'SELECT source_kind,source_id,game_id,package_key_kind,package_key,file_id '
            . 'FROM ue_uedb5_provider_keys WHERE file_id=? ORDER BY source_kind,source_id',
            [$fileId]
        );
        $this->assertRowsEqual($expectedProvider, $actualProvider, ['package_key'], 'provider_projection_mismatch');
        $actualNames = $this->dbRows(
            'SELECT file_id,name_key_hash,name_key_length,name_key_fingerprint,first_name_index '
            . 'FROM ue_uedb5_name_candidates WHERE file_id=? ORDER BY name_key_fingerprint',
            [$fileId]
        );
        $this->assertRowsEqual($expected['name_candidates'], $actualNames, ['name_key_hash','name_key_fingerprint'], 'name_projection_mismatch');

        $actualObjects = $this->dbRows(
            'SELECT file_id,object_kind,object_index,object_name_hash,object_name_length,public_export_hash '
            . 'FROM ue_uedb5_object_candidates WHERE file_id=? ORDER BY object_kind,object_index',
            [$fileId]
        );
        $this->assertRowsEqual($expected['object_candidates'], $actualObjects, ['object_name_hash','public_export_hash'], 'object_projection_mismatch');
        $this->validateSearchKeys((array)$expected['search_keys']);
        return [
            'provider_keys' => count($expectedProvider),
            'search_keys' => count((array)$expected['search_keys']),
            'name_candidates' => count((array)$expected['name_candidates']),
            'object_candidates' => count((array)$expected['object_candidates']),
        ];
    }

    /** @return array<string,mixed> */
    private function validateDependencies(array $snapshot, array $context): array
    {
        $sections = (array)($snapshot['sections'] ?? []);
        $classic = (string)($snapshot['package_family'] ?? '') !== Uedb5ZenPackageReader::PACKAGE_FAMILY;
        $expectedCount = $classic
            ? count((array)($sections['imports'] ?? []))
            : $this->zenDependencySourceCount($snapshot);
        $hasResults = array_key_exists('dependency_results', $sections);
        if (!$hasResults && $expectedCount > 0) {
            return [
                'ready' => false,
                'reason' => 'dependency_results_not_built',
                'expected_dependency_count' => $expectedCount,
                'dependency_count' => 0,
            ];
        }
        $results = array_values((array)($sections['dependency_results'] ?? []));
        $this->require(
            count($results) === $expectedCount,
            'dependency_count_mismatch',
            'UEDB5 dependency result count does not match the source dependency count.'
        );
        $projection = Uedb5DependencyProjectionBuilder::build($snapshot);
        $fileId = (int)$context['id'];
        $actualEdges = $this->dbRows(
            'SELECT file_id,source_kind,source_index,classification,outcome,required_package_key_kind,'
            . 'required_package_key,required_object_key_kind,required_object_key,resolved_file_id,'
            . 'resolved_object_kind,resolved_object_index FROM ue_uedb5_dependency_edges '
            . 'WHERE file_id=? ORDER BY source_kind,source_index',
            [$fileId]
        );
        $this->assertRowsEqual(
            (array)$projection['dependency_edges'],
            $actualEdges,
            ['required_package_key','required_object_key'],
            'dependency_edge_projection_mismatch'
        );
        $actualPackages = $this->dbRows(
            'SELECT game_id,file_id,package_key_kind,package_key,required_package_name,dependency_count,'
            . 'resolved_count,missing_count,package_only_count,common_count,unresolved_count,'
            . 'hard_missing_count,nonhard_missing_count,summary_outcome,provider_file_id '
            . 'FROM ue_uedb5_dependency_packages WHERE file_id=? ORDER BY package_key_kind,package_key',
            [$fileId]
        );
        $this->assertRowsEqual(
            (array)$projection['dependency_packages'],
            $actualPackages,
            ['package_key'],
            'dependency_package_projection_mismatch'
        );
        return [
            'ready' => true,
            'expected_dependency_count' => $expectedCount,
            'dependency_count' => count($results),
            'dependency_edges' => count((array)$projection['dependency_edges']),
            'dependency_packages' => count((array)$projection['dependency_packages']),
        ];
    }

    private function zenDependencySourceCount(array $snapshot): int
    {
        $sections = (array)($snapshot['sections'] ?? []);
        $count = $this->zenHeaderDependencySourceCount($sections);
        $optional = (array)(($sections['optional_segment'] ?? [])[0] ?? []);
        if ($optional !== []) {
            $count += $this->zenHeaderDependencySourceCount($optional);
        }
        return $count;
    }

    /** @param array<string,mixed> $sections */
    private function zenHeaderDependencySourceCount(array $sections): int
    {
        $count = 0;
        foreach (['imports','cell_imports'] as $section) {
            foreach ((array)($sections[$section] ?? []) as $row) {
                if (in_array((string)((array)$row)['type'], ['PackageImport','ScriptImport'], true)) { $count++; }
            }
        }
        $count += count((array)($sections['soft_package_references'] ?? []));
        foreach ((array)($sections['dependency_bundle_entries'] ?? []) as $edge) {
            $edge = (array)$edge;
            if (($edge['target_type'] ?? null) === 'Export') { $count++; continue; }
            if (($edge['target_type'] ?? null) !== 'Import') { continue; }
            $combined = (int)($edge['combined_import_index'] ?? -1);
            $imports = array_values((array)($sections['imports'] ?? []));
            $cells = array_values((array)($sections['cell_imports'] ?? []));
            $row = $combined >= 0 && $combined < count($imports)
                ? (array)$imports[$combined]
                : (array)($cells[$combined - count($imports)] ?? []);
            if (in_array((string)($row['type'] ?? ''), ['PackageImport','ScriptImport'], true)) { $count++; }
        }
        return $count;
    }

    /** @param list<array<string,mixed>> $expected */
    private function validateSearchKeys(array $expected): void
    {
        if ($expected === []) { return; }
        $actual = [];
        foreach (array_chunk($expected, 400) as $chunk) {
            $fingerprints = array_map(static fn(array $row): string => (string)$row['fingerprint'], $chunk);
            $sql = 'SELECT key_hash,key_length,key_fingerprint,normalized_text FROM ue_uedb5_search_keys '
                . 'WHERE key_fingerprint IN (' . implode(',', array_fill(0, count($fingerprints), '?')) . ')';
            foreach ($this->dbRows($sql, $fingerprints) as $row) {
                $actual[bin2hex((string)$row['key_fingerprint'])] = $row;
            }
        }
        foreach ($expected as $row) {
            $finger = bin2hex((string)$row['fingerprint']);
            $got = $actual[$finger] ?? null;
            $this->require(is_array($got), 'search_projection_missing', 'A UEDB5 normalized search key is missing from SQL.');
            $this->require(
                hash_equals((string)$row['hash'], (string)$got['key_hash'])
                && (int)$row['length'] === (int)$got['key_length']
                && (string)$row['normalized_text'] === (string)$got['normalized_text'],
                'search_projection_mismatch',
                'A UEDB5 normalized search key disagrees with SQL.'
            );
        }
    }

    /** @param list<mixed> $params @return list<array<string,mixed>> */
    private function dbRows(string $sql, array $params): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @param list<array<string,mixed>> $expected @param list<array<string,mixed>> $actual @param list<string> $binaryFields */
    private function assertRowsEqual(array $expected, array $actual, array $binaryFields, string $code): void
    {
        $left = $this->canonicalRows($expected, $binaryFields);
        $right = $this->canonicalRows($actual, $binaryFields);
        $this->require($left === $right, $code, 'UEDB5-derived SQL projection rows do not exactly match the authoritative V5 snapshot.');
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $binaryFields @return list<string> */
    private function canonicalRows(array $rows, array $binaryFields): array
    {
        $out = [];
        foreach ($rows as $row) {
            $normalized = [];
            foreach ((array)$row as $key => $value) {
                if (in_array((string)$key, $binaryFields, true)) {
                    $normalized[(string)$key] = $value === null ? null : bin2hex((string)$value);
                    continue;
                }
                $normalized[(string)$key] = $this->canonicalValue((string)$key, $value);
            }
            ksort($normalized, SORT_STRING);
            $out[] = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private function canonicalValue(string $key, mixed $value): mixed
    {
        if ($value === null) { return null; }
        if (preg_match('/(?:^|_)(?:id|index|kind|length|count)$/', $key) === 1
            || in_array($key, ['classification','outcome','source_kind','summary_outcome'], true)) {
            return (int)$value;
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function summaryRow(array $snapshot): array
    {
        $sections = (array)($snapshot['sections'] ?? []);
        if (isset($sections['summary'][0])) { return (array)$sections['summary'][0]; }
        if (isset($sections['package_summary'][0])) { return (array)$sections['package_summary'][0]; }
        throw new Uedb5ValidationException('summary_missing', 'UEDB5 snapshot has no package summary row.');
    }

    private function canonicalSnapshot(mixed $value): string
    {
        $normalize = function (mixed $node) use (&$normalize): mixed {
            if (!is_array($node)) { return $node; }
            $isList = array_is_list($node);
            $out = [];
            foreach ($node as $key => $item) { $out[$key] = $normalize($item); }
            if (!$isList) { ksort($out, SORT_STRING); }
            return $out;
        };
        return (string)json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function requireKeys(array $row, array $keys, string $label): void
    {
        foreach ($keys as $key) {
            $this->require(array_key_exists($key, $row), 'engine_field_missing', $label . ' is missing source field ' . $key . '.');
        }
    }

    private function require(bool $condition, string $code, string $message): void
    {
        if (!$condition) {
            throw new Uedb5ValidationException($code, $message);
        }
    }
}
