<?php
declare(strict_types=1);

require_once __DIR__ . '/UnverifiedFileManager.php';

/**
 * Reads one queued package without storing it. The result strengthens the
 * filename/package-name reference hint by testing whether the actual exports
 * from that package match object paths currently requested by catalog imports.
 */

function uvoc_reader_engine(array $item): string
{
    $engine = strtoupper(trim((string)($item['header']['engine'] ?? '')));
    if (in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        return $engine;
    }

    $fallback = strtoupper((string)(gp_detect_from_extension((string)($item['extension'] ?? '')) ?? ''));
    if (in_array($fallback, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        return $fallback;
    }

    throw new RuntimeException('Could not determine a package reader for this queued file.');
}

/**
 * @return array{engine:string,name_count:int,import_count:int,export_count:int,exports:array<string,string>}
 */
function uvoc_read_exports(array $config, array $item): array
{
    $engine = uvoc_reader_engine($item);
    $readerClass = scanner_load_reader_class($config, $engine);
    $reader = new $readerClass((string)$item['path']);
    $issues = method_exists($reader, 'validatePackage') ? $reader->validatePackage() : (method_exists($reader, 'getDebugErrors') ? $reader->getDebugErrors() : []);
    [$fatalIssues] = scanner_split_reader_issues(is_array($issues) ? $issues : []);
    if ($fatalIssues !== []) {
        throw new RuntimeException(implode("\n", $fatalIssues));
    }
    foreach (['getNames', 'getImports', 'getExports'] as $method) {
        if (!method_exists($reader, $method)) {
            throw new RuntimeException('Reader is missing method: ' . $method);
        }
    }

    $names = $reader->getNames();
    $imports = $reader->getImports();
    $exports = $reader->getExports();
    if (!is_array($names) || !is_array($imports) || !is_array($exports)) {
        throw new RuntimeException('Reader returned an invalid package table.');
    }

    $paths = [];
    $cache = [];
    $packageName = scanner_clean_name((string)$item['package_name']);
    foreach ($exports as $index => $export) {
        $localPath = scanner_ref_path((int)$index + 1, $imports, $exports, $cache);
        $fullPath = scanner_join_path_parts([$packageName, $localPath]);
        if ($fullPath !== '') {
            $paths[strtolower($fullPath)] = $fullPath;
        }
    }

    return [
        'engine' => $engine,
        'name_count' => count($names),
        'import_count' => count($imports),
        'export_count' => count($exports),
        'exports' => $paths,
    ];
}

/**
 * @return array{item:array<string,mixed>,reader:array<string,mixed>,candidates:list<array<string,mixed>>}
 */
function uvoc_check(PDO $db, array $config, string $token): array
{
    $item = uvf_resolve($db, $config, $token);
    $reader = uvoc_read_exports($config, $item);
    $packageKey = strtolower(trim((string)$item['package_name']));
    if ($packageKey === '') {
        throw new RuntimeException('Queued file has no usable package name.');
    }

    $rows = catalog_all(
        $db,
        'SELECT g.id game_id, g.name game_name, d.file_id, d.required_object_path'
        . ' FROM ue_dependencies d'
        . ' JOIN ue_files f ON f.id=d.file_id'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' WHERE LOWER(d.required_package)=?'
        . ' ORDER BY g.name, d.file_id, d.id',
        [$packageKey]
    );

    $byGame = [];
    foreach ($rows as $row) {
        $gameId = (int)$row['game_id'];
        $path = trim((string)$row['required_object_path']);
        $pathKey = strtolower($path);
        $byGame[$gameId] ??= [
            'game_id' => $gameId,
            'game_name' => (string)$row['game_name'],
            'import_count' => 0,
            'owner_ids' => [],
            'exact_object_matches' => 0,
            'unmatched_object_count' => 0,
            'matched_paths' => [],
        ];
        $byGame[$gameId]['import_count']++;
        $byGame[$gameId]['owner_ids'][(int)$row['file_id']] = true;
        if ($pathKey !== '' && isset($reader['exports'][$pathKey])) {
            $byGame[$gameId]['exact_object_matches']++;
            if (count($byGame[$gameId]['matched_paths']) < 12) {
                $byGame[$gameId]['matched_paths'][$pathKey] = $reader['exports'][$pathKey];
            }
        } else {
            $byGame[$gameId]['unmatched_object_count']++;
        }
    }

    $candidates = [];
    foreach ($byGame as $candidate) {
        $candidate['owner_count'] = count($candidate['owner_ids']);
        unset($candidate['owner_ids']);
        $candidate['matched_paths'] = array_values($candidate['matched_paths']);
        $candidates[] = $candidate;
    }
    usort($candidates, static fn(array $left, array $right): int => ($right['exact_object_matches'] <=> $left['exact_object_matches']) ?: strcmp((string)$left['game_name'], (string)$right['game_name']));

    return [
        'item' => $item,
        'reader' => $reader,
        'candidates' => $candidates,
    ];
}
