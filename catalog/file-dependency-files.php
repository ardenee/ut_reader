<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogPackageAliases.php';
require_once __DIR__ . '/lib/CatalogDependencySchema.php';

function fd_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fd_key(string $value): string
{
    $value = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

/** @return array{0:string,1:list<string>} */
function fd_in_values(array $values): array
{
    $values = array_values(array_unique(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $values
    ), static fn(string $value): bool => $value !== '')));
    return [implode(',', array_fill(0, count($values), '?')), $values];
}

/**
 * @param list<string> $packageNames
 * @return array<string,list<array<string,mixed>>>
 */
function fd_package_candidates(PDO $db, int $gameId, array $packageNames): array
{
    [$inSql, $names] = fd_in_values($packageNames);
    if ($names === []) {
        return [];
    }

    catalog_package_aliases_ensure($db);
    $rows = catalog_all(
        $db,
        'SELECT f.package_name lookup_name, \'exact_package\' match_source, f.id, f.package_name, f.original_name, f.package_guid, f.md5, f.file_size, f.uploaded_at'
        . ' FROM ue_files f'
        . ' WHERE f.game_id=? AND f.scan_status=\'verified\' AND f.package_name IN (' . $inSql . ')'
        . ' UNION ALL'
        . ' SELECT a.package_name lookup_name, \'exact_package_alias\' match_source, f.id, f.package_name, f.original_name, f.package_guid, f.md5, f.file_size, f.uploaded_at'
        . ' FROM ue_file_package_aliases a'
        . ' JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id AND f.scan_status=\'verified\''
        . ' WHERE a.game_id=? AND a.package_name IN (' . $inSql . ')'
        . ' ORDER BY uploaded_at DESC, id DESC',
        array_merge([$gameId], $names, [$gameId], $names)
    );

    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $key = fd_key((string)$row['lookup_name']);
        $fileId = (int)$row['id'];
        if ($key === '' || isset($seen[$key][$fileId])) {
            continue;
        }
        $seen[$key][$fileId] = true;
        $out[$key][] = $row;
    }
    return $out;
}

/** @return array<string,mixed>|null */
function fd_select_candidate(array $candidateMap, string $packageName, int $sourceFileId): ?array
{
    $candidates = $candidateMap[fd_key($packageName)] ?? [];
    foreach ($candidates as $candidate) {
        if ((int)$candidate['id'] === $sourceFileId) {
            return $candidate;
        }
    }
    return $candidates[0] ?? null;
}

/** @return array<string,mixed> */
function fd_file_payload(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'file' => (string)$row['original_name'],
        'package' => (string)$row['package_name'],
        'size' => (int)$row['file_size'],
        'size_text' => catalog_bytes((int)$row['file_size']),
        'guid' => (string)($row['package_guid'] ?? ''),
        'md5' => (string)($row['md5'] ?? ''),
    ];
}

/** @return list<array<string,mixed>> */
function fd_sort_files(array $files): array
{
    $files = array_values($files);
    usort($files, static function (array $left, array $right): int {
        return strnatcasecmp((string)$left['file'], (string)$right['file']);
    });
    return $files;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_dependency_schema_ensure($db);
    $fileId = max(0, (int)($_GET['id'] ?? 0));
    $file = catalog_one(
        $db,
        'SELECT id, game_id, package_name, original_name, package_guid, md5, file_size FROM ue_files WHERE id=?',
        [$fileId]
    );
    if (!$file) {
        fd_json(['ok' => false, 'error' => 'File not found.'], 404);
    }

    catalog_package_aliases_ensure($db);
    $dependencies = catalog_all(
        $db,
        'SELECT d.*, rf.id resolved_id, rf.package_name resolved_package, rf.original_name resolved_file,'
        . ' rf.package_guid resolved_guid, rf.md5 resolved_md5, rf.file_size resolved_size'
        . ' FROM ue_dependencies d'
        . ' LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id'
        . ' WHERE d.file_id=?'
        . ' ORDER BY d.required_package, d.required_object_path, d.id',
        [$fileId]
    );

    $unresolvedNames = [];
    foreach ($dependencies as $dependency) {
        if ((int)($dependency['resolved_id'] ?? 0) === 0 && (string)($dependency['status'] ?? '') !== 'common') {
            $unresolvedNames[] = (string)$dependency['required_package'];
        }
    }
    $candidateMap = fd_package_candidates($db, (int)$file['game_id'], $unresolvedNames);

    $dependencyPayload = [];
    $requires = [];
    foreach ($dependencies as $dependency) {
        $status = (string)($dependency['status'] ?? 'missing');
        $resolved = null;
        $source = (string)($dependency['resolution_source'] ?? 'none');
        $confidence = (string)($dependency['resolution_confidence'] ?? 'missing');

        if ((int)($dependency['resolved_id'] ?? 0) > 0) {
            $resolved = [
                'id' => (int)$dependency['resolved_id'],
                'file' => (string)$dependency['resolved_file'],
                'package' => (string)$dependency['resolved_package'],
                'size' => (int)$dependency['resolved_size'],
                'size_text' => catalog_bytes((int)$dependency['resolved_size']),
                'guid' => (string)$dependency['resolved_guid'],
                'md5' => (string)$dependency['resolved_md5'],
            ];
        } elseif ($status !== 'common') {
            $candidate = fd_select_candidate($candidateMap, (string)$dependency['required_package'], $fileId);
            if ($candidate !== null) {
                $resolved = fd_file_payload($candidate);
                $source = (string)$candidate['match_source'];
                $confidence = 'exact package';
                if (trim((string)$dependency['required_object_path']) !== '') {
                    $status = 'object_missing';
                } else {
                    $status = 'package_only';
                }
            }
        }

        if ($resolved !== null && (int)$resolved['id'] !== $fileId && $status !== 'common') {
            $requires[(int)$resolved['id']] = $resolved;
        }

        $dependencyPayload[] = [
            'id' => (int)$dependency['id'],
            'status' => $status,
            'source' => $source,
            'confidence' => $confidence,
            'required_package' => (string)$dependency['required_package'],
            'required_object' => (string)$dependency['required_object_path'],
            'resolved_file' => $resolved,
        ];
    }

    $identityRows = catalog_all(
        $db,
        'SELECT package_name FROM ue_file_package_aliases WHERE game_id=? AND file_id=? ORDER BY package_name',
        [(int)$file['game_id'], $fileId]
    );
    $identityNames = [(string)$file['package_name']];
    foreach ($identityRows as $identityRow) {
        $identityNames[] = (string)$identityRow['package_name'];
    }
    [$identitySql, $identityNames] = fd_in_values($identityNames);

    $requiredBy = [];
    if ($identityNames !== []) {
        $reverseRows = catalog_all(
            $db,
            'SELECT d.id dependency_id, d.file_id source_file_id, d.required_package, d.required_object_path, d.status, d.resolved_file_id,'
            . ' src.id, src.package_name, src.original_name, src.package_guid, src.md5, src.file_size'
            . ' FROM ue_dependencies d'
            . ' JOIN ue_files src ON src.id=d.file_id AND src.game_id=? AND src.scan_status=\'verified\''
            . ' WHERE src.id<>? AND (d.resolved_file_id=? OR d.required_package IN (' . $identitySql . '))'
            . ' ORDER BY src.original_name, d.id',
            array_merge([(int)$file['game_id'], $fileId, $fileId], $identityNames)
        );

        $reverseNames = [];
        foreach ($reverseRows as $row) {
            if ((int)($row['resolved_file_id'] ?? 0) === 0 && (string)($row['status'] ?? '') !== 'common') {
                $reverseNames[] = (string)$row['required_package'];
            }
        }
        $reverseCandidates = fd_package_candidates($db, (int)$file['game_id'], $reverseNames);

        foreach ($reverseRows as $row) {
            $targetId = (int)($row['resolved_file_id'] ?? 0);
            if ($targetId === 0 && (string)($row['status'] ?? '') !== 'common') {
                $candidate = fd_select_candidate($reverseCandidates, (string)$row['required_package'], (int)$row['source_file_id']);
                $targetId = $candidate !== null ? (int)$candidate['id'] : 0;
            }
            if ($targetId === $fileId) {
                $requiredBy[(int)$row['id']] = fd_file_payload($row);
            }
        }
    }

    $counts = ['all' => count($dependencyPayload), 'missing' => 0, 'object_missing' => 0, 'package_only' => 0, 'resolved' => 0, 'common' => 0];
    foreach ($dependencyPayload as $dependency) {
        $status = (string)$dependency['status'];
        if (array_key_exists($status, $counts)) {
            $counts[$status]++;
        }
    }

    fd_json([
        'ok' => true,
        'file' => fd_file_payload($file),
        'dependencies' => $dependencyPayload,
        'dependency_counts' => $counts,
        'requires' => fd_sort_files($requires),
        'required_by' => fd_sort_files($requiredBy),
    ]);
} catch (Throwable $error) {
    fd_json(['ok' => false, 'error' => $error->getMessage()], 500);
}
