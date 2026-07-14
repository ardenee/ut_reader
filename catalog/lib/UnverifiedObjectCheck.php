<?php
declare(strict_types=1);

require_once __DIR__ . '/UnverifiedFileManager.php';
require_once __DIR__ . '/CatalogUE4ParserProfile.php';

/**
 * Reads one queued Unreal package without storing it. The result strengthens the
 * filename/package-name reference hint by testing whether the actual exports
 * from that package match object paths currently requested by catalog imports.
 */

/**
 * @return array{valid:bool,found_tag:string,found_hex:string,found_text:string,expected_tag:string}
 */
function uvoc_package_signature(string $path): array
{
    $bytes = @file_get_contents($path, false, null, 0, 16);
    if (!is_string($bytes) || strlen($bytes) < 4) {
        return [
            'valid' => false,
            'found_tag' => 'unavailable',
            'found_hex' => strtoupper(bin2hex((string)$bytes)),
            'found_text' => '',
            'expected_tag' => '0x9E2A83C1',
        ];
    }

    $tag = (int)unpack('V', substr($bytes, 0, 4))[1];
    $text = preg_replace('/[^\x20-\x7E]/', '.', substr($bytes, 0, 4)) ?? '';
    return [
        'valid' => $tag === 0x9E2A83C1,
        'found_tag' => sprintf('0x%08X', $tag),
        'found_hex' => strtoupper(bin2hex(substr($bytes, 0, 4))),
        'found_text' => $text,
        'expected_tag' => '0x9E2A83C1',
    ];
}

function uvoc_public_reader_error(Throwable $error): string
{
    $message = trim(preg_replace('/\s+/', ' ', $error->getMessage()) ?? '');
    if ($message === '') {
        return 'The detected package reader could not read the package tables.';
    }
    return strlen($message) > 300 ? substr($message, 0, 297) . '...' : $message;
}

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
 * @param array<int, mixed> $names
 * @param array<int, mixed> $imports
 * @param array<int, mixed> $exports
 * @return array{names:list<array<string,mixed>>,imports:list<array<string,mixed>>,exports:list<array<string,mixed>>}
 */
function uvoc_build_tables(array $names, array $imports, array $exports, string $packageName): array
{
    $nameRows = [];
    foreach ($names as $index => $name) {
        $nameRows[] = [
            'name_index' => (int)$index,
            'name_text' => (string)($name['name'] ?? $name['text'] ?? ''),
            'flags' => isset($name['flags']) ? (int)$name['flags'] : null,
        ];
    }

    $cache = [];
    $importRows = [];
    foreach ($imports as $index => $import) {
        $fullPath = scanner_ref_path(-((int)$index + 1), $imports, $exports, $cache);
        $parts = $fullPath !== '' ? explode('.', $fullPath) : [];
        $rootPackage = (string)($parts[0] ?? '');
        $relativePath = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
        $importRows[] = [
            'import_index' => (int)$index,
            'class_package' => (string)($import['classPackageText'] ?? ($import['ClassPackage']['text'] ?? '')),
            'class_name' => (string)($import['classNameText'] ?? ($import['ClassName']['text'] ?? '')),
            'object_name' => (string)($import['objectNameText'] ?? ($import['ObjectName']['text'] ?? '')),
            'outer_index' => (int)($import['outerIndex'] ?? $import['OuterIndex'] ?? $import['outer'] ?? 0),
            'root_package' => $rootPackage,
            'relative_object_path' => $relativePath,
            'full_path' => $fullPath,
        ];
    }

    $exportRows = [];
    foreach ($exports as $index => $export) {
        $localPath = scanner_ref_path((int)$index + 1, $imports, $exports, $cache);
        $classRef = (int)($export['classIndex'] ?? $export['class'] ?? 0);
        $className = $classRef !== 0 ? scanner_ref_path($classRef, $imports, $exports, $cache) : '';
        $exportRows[] = [
            'export_index' => (int)$index,
            'class_name' => $className,
            'object_name' => (string)($export['objectNameText'] ?? ''),
            'outer_index' => (int)($export['outerIndex'] ?? $export['packageIndex'] ?? $export['outer'] ?? 0),
            'local_path' => $localPath,
            'full_path' => scanner_join_path_parts([$packageName, $localPath]),
            'object_flags' => isset($export['objectFlags']) ? (int)$export['objectFlags'] : null,
            'serial_size' => isset($export['serialSize']) ? (int)$export['serialSize'] : null,
            'serial_offset' => isset($export['serialOffset']) ? (int)$export['serialOffset'] : null,
        ];
    }

    return [
        'names' => $nameRows,
        'imports' => $importRows,
        'exports' => $exportRows,
    ];
}

function uvoc_set_reader_profile(array $config, array $item, string $engine): void
{
    if (!in_array($engine, ['UE4', 'UE5'], true)) {
        return;
    }

    $game = [];
    $profile = [];
    $gameId = (int)($item['game_id'] ?? ($item['header']['game_id'] ?? 0));
    if ($gameId > 0) {
        try {
            $db = catalog_db($config);
            $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]) ?: [];
            $profile = gp_required_profile_for_game($db, $gameId);
        } catch (Throwable $e) {
            error_log('[UnrealDB object check] parser profile fallback: ' . $e->getMessage());
        }
    }

    catalog_ue4_set_next_reader_options(catalog_ue4_reader_options($config, $game, $profile));
}

/**
 * @return array{engine:string,name_count:int,import_count:int,export_count:int,exports:array<string,string>,tables:array{names:list<array<string,mixed>>,imports:list<array<string,mixed>>,exports:list<array<string,mixed>>}}
 */
function uvoc_read_exports(array $config, array $item): array
{
    $engine = uvoc_reader_engine($item);
    $readerClass = scanner_load_reader_class($config, $engine);
    uvoc_set_reader_profile($config, $item, $engine);
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

    $packageName = scanner_clean_name((string)$item['package_name']);
    $tables = uvoc_build_tables($names, $imports, $exports, $packageName);
    $paths = [];
    foreach ($tables['exports'] as $export) {
        $fullPath = (string)$export['full_path'];
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
        'tables' => $tables,
    ];
}

/**
 * @return array{item:array<string,mixed>,reader:array<string,mixed>|null,candidates:list<array<string,mixed>>,analysis_error:?array<string,mixed>}
 */
function uvoc_check(PDO $db, array $config, string $token): array
{
    $item = uvf_resolve($db, $config, $token);
    $signature = uvoc_package_signature((string)$item['path']);
    if (!$signature['valid']) {
        return [
            'item' => $item,
            'reader' => null,
            'candidates' => [],
            'analysis_error' => [
                'code' => 'invalid_package_signature',
                'message' => 'This file does not begin with the official Unreal package signature, so it is not processed as a catalog package.',
                'signature' => $signature,
            ],
        ];
    }

    try {
        $reader = uvoc_read_exports($config, $item);
    } catch (Throwable $error) {
        error_log('[UnrealDB object check] package=' . (string)$item['path'] . ' error=' . $error->getMessage());
        return [
            'item' => $item,
            'reader' => null,
            'candidates' => [],
            'analysis_error' => [
                'code' => 'reader_failed',
                'message' => uvoc_public_reader_error($error),
                'signature' => $signature,
            ],
        ];
    }

    $packageKey = strtolower(trim((string)$item['package_name']));
    if ($packageKey === '') {
        return [
            'item' => $item,
            'reader' => $reader,
            'candidates' => [],
            'analysis_error' => [
                'code' => 'missing_package_name',
                'message' => 'The queued filename does not provide a usable package name for dependency comparison.',
                'signature' => $signature,
            ],
        ];
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
        'analysis_error' => null,
    ];
}
