<?php
declare(strict_types=1);

require_once __DIR__ . '/UnverifiedFileManager.php';
require_once __DIR__ . '/CatalogUE4ParserProfile.php';
require_once __DIR__ . '/CatalogUnverifiedIndex.php';
require_once __DIR__ . '/CatalogUnverifiedGameMatches.php';

/**
 * Object Check prefers the database-staged Names, Imports and Exports. Physical
 * parsing remains only as a fallback for queue files that have not been backfilled.
 */

function uvoc_emit_progress(?callable $progress, string $stage, string $message, int $percent): void
{
    if ($progress !== null) {
        $progress(['stage' => $stage, 'message' => $message, 'percent' => max(0, min(100, $percent))]);
    }
}

/** @return array{valid:bool,found_tag:string,found_hex:string,found_text:string,expected_tag:string} */
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
 * @param array<int,mixed> $names
 * @param array<int,mixed> $imports
 * @param array<int,mixed> $exports
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

    return ['names' => $nameRows, 'imports' => $importRows, 'exports' => $exportRows];
}

function uvoc_set_reader_profile(array $config, array $item, string $engine): void
{
    if (!in_array($engine, ['UE4', 'UE5'], true)) {
        return;
    }

    $game = [];
    $profile = [];
    $gameId = (int)($item['game']['id'] ?? $item['game_id'] ?? ($item['header']['game_id'] ?? 0));
    if ($gameId > 0) {
        try {
            $db = catalog_db($config);
            $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]) ?: [];
            $profile = gp_required_profile_for_game($db, $gameId);
        } catch (Throwable $error) {
            error_log('[UnrealDB object check] parser profile fallback: ' . $error->getMessage());
        }
    }

    catalog_ue4_set_next_reader_options(catalog_ue4_reader_options($config, $game, $profile));
}

/** @return array<string,mixed> */
function uvoc_read_exports(array $config, array $item, ?callable $progress = null): array
{
    uvoc_emit_progress($progress, 'detect_reader', 'Detecting the package reader', 14);
    $engine = uvoc_reader_engine($item);
    uvoc_emit_progress($progress, 'load_reader', 'Loading the ' . $engine . ' package reader', 20);
    $readerClass = scanner_load_reader_class($config, $engine);
    uvoc_set_reader_profile($config, $item, $engine);

    uvoc_emit_progress($progress, 'open_package', 'Opening the package', 26);
    $reader = new $readerClass((string)$item['path']);
    uvoc_emit_progress($progress, 'validate_package', 'Validating the package structure', 32);
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

    uvoc_emit_progress($progress, 'read_names', 'Reading the Names table', 42);
    $names = $reader->getNames();
    uvoc_emit_progress($progress, 'read_imports', 'Reading the Imports table', 54);
    $imports = $reader->getImports();
    uvoc_emit_progress($progress, 'read_exports', 'Reading the Exports table', 66);
    $exports = $reader->getExports();
    if (!is_array($names) || !is_array($imports) || !is_array($exports)) {
        throw new RuntimeException('Reader returned an invalid package table.');
    }

    uvoc_emit_progress($progress, 'build_paths', 'Building object paths from package references', 76);
    $packageName = scanner_clean_name((string)][VÉÜXÚØYÙWÛ˜[YI×JNÂˆ	X›\ÈH]›Ø×ØZ[İX›\Ê	˜[Y\Ë	[\ÜË	^ÜË	XÚØYÙS˜[YJNÂˆ	]ÈH×NÂˆ›Ü™XXÚ
	X›\ÖÉÙ^ÜÉ×H\È	^Ü
HÂˆ	[]H
İš[™ÊI^ÜÉÙ[Ü]	×NÂˆYˆ
	[]OOH	ÉÊHÂˆ	]ÖÜİÛİÙ\Š	[]
WHH	[]ÂˆBˆB‚ˆ]›Ø×Ù[Z]Ü›ÙÜ™\ÜÊ	›ÙÜ™\ÜË	ÜXÚØYÙWİX›\×Ü™XYIË	ÔXÚØYÙHX›\È\™H™XYIËŠNÂˆ™]\›ˆÂˆ	ÜÛİ\˜ÙIÈOˆ	Ü\ÚXØ[	Ëˆ	Ù[™Ú[™IÈOˆ	[™Ú[™Kˆ	Û˜[YWØÛİ[	ÈOˆÛİ[
	˜[Y\ÊKˆ	Ú[\ÜØÛİ[	ÈOˆÛİ[
	[\ÜÊKˆ	Ù^ÜØÛİ[	ÈOˆÛİ[
	^ÜÊKˆ	Ù^ÜÉÈOˆ	]Ëˆ	İX›\ÉÈOˆ	X›\ËˆNÂŸB‚‹ÊŠˆ™]\›ˆ\œ˜^Oİš[™ËZ^Yˆ
‹Â™[˜İ[Ûˆ]›Ø×ÜİYÙYÜ™XY\ŠÈ	‹\œ˜^H	İYÙYØØ[X›H	›ÙÜ™\ÜÈH[
Nˆ\œ˜^BÂˆ	š[RYH
[
IİYÙYÉÚY	×NÂˆ]›Ø×Ù[Z]Ü›ÙÜ™\ÜÊ	›ÙÜ™\ÜË	ÛØYÜİYÙYÛ˜[Y\ÉË	ÓØY[™ÈİYÙY˜[Y\Èœ›ÛHH]X˜\ÙIËÍ
NÂˆ	˜[Y\ÈHØ][Ù×Ø[
	‹	ÔÑSPÕ˜[YWÚ[™^˜[YWİ^›YÜÈ”“ÓHYWÛ˜[Y\ÈÒT‘Hš[WÚYOÈÔ‘Tˆ–H˜[YWÚ[™^	ËÉš[RYJNÂˆ]›Ø×Ù[Z]Ü›ÙÜ™\ÜÊ	›ÙÜ™\ÜË	ÛØYÜİYÙYÚ[\ÜÉË	ÓØY[™ÈİYÙY[\ÜÈœ›ÛHH]X˜\ÙIËL
NÂˆ	[\ÜÈHØ][Ù×Ø[
	‹	ÔÑSPÕ[\ÜÚ[™^Û\Ü×ÜXÚØYÙKÛ\Ü×Û˜[YKØš™XİÛ˜[YKİ]\—Ú[™^›ÛİÜXÚØYÙK™[]]™WÛØš™XİÜ][Ü]”“ÓHYWÚ[\ÜÈÒT‘Hš[WÚYOÈÔ‘Tˆ–H[\ÜÚ[™^	ËÉš[RYJNÂˆ]›Ø×Ù[Z]Ü›ÙÜ™\ÜÊ	›ÙÜ™\ÜË	ÛØYÜİYÙYÙ^ÜÉË	ÓØY[™ÈİYÙY^ÜÈœ›ÛHH]X˜\ÙIËŠNÂˆ	^ÜÈHØ][Ù×Ø[
	‹	ÔÑSPÕ^ÜÚ[™^Û\Ü×Û˜[YKØš™XİÛ˜[YKİ]\—Ú[™^ØØ[Ü][Ü]Øš™XİÙ›YÜËÙ\šX[ÜÚ^™KÙ\šX[ÛÙ™œÙ]”“ÓHYWÙ^ÜÈÒT‘Hš[WÚYOÈÔ‘Tˆ–H^ÜÚ[™^	ËÉš[RYJNÂ‚ˆ	]ÈH×NÂˆ›Ü™XXÚ
	^ÜÈ\È	^Ü
HÂˆ	[]Hš[J
İš[™ÊI^ÜÉÙ[Ü]	×JNÂˆYˆ
	[]OOH	ÉÊHÂˆ	]ÖÜİÛİÙ\Š	[]
WHH	[]ÂˆBˆB‚ˆ]›Ø×Ù[Z]Ü›ÙÜ™\ÜÊ	›ÙÜ™\ÜË	ÜİYÙYİX›\×Ü™XYIË	ÔİÜ™YXÚØYÙHX›\È\™H™XYIËŠNÂˆ™]\›ˆÂˆ	ÜÛİ\˜ÙIÈOˆ	Ù]X˜\ÙIËˆ	Ù[™Ú[™IÈOˆİİ\\Š
İš[™ÊJ	İYÙYÉÙ]XİYÙ[™Ú[™WÚÙ^I×HÏÈ	ÕS’Ó“ÕÓ‰ÊJKˆ	Û˜[YWØÛİ[	ÈOˆÛİ[
	˜[Y\ÊKˆ	Ú[\ÜØÛİ[	ÈOˆÛİ[
	[\ÜÊKˆ	Ù^ÜØÛİ[	ÈOˆÛİ[
	^ÜÊKˆ	Ù^ÜÉÈOˆ	]Ëˆ	İX›\ÉÈOˆÉÛ˜[Y\ÉÈOˆ	˜[Y\Ë	Ú[\ÜÉÈOˆ	[\ÜË	Ù^ÜÉÈOˆ	^Ü×KˆNÂŸB‚‹ÊŠˆ™]\›ˆ\İ\œ˜^Oİš[™ËZ^Yˆ
‹Â™[˜İ[Ûˆ]›Ø×ÜİYÙYØØ[™Y]\ÊÈ	‹\œ˜^H	İYÙY
Nˆ\œ˜^BÂˆ	š[RYH
[
IİYÙYÉÚY	×NÂˆ	XÚØYÙS˜[YHH
İš[™ÊIİYÙYÉÜXÚØYÙWÛ˜[YI×NÂˆ	˜[šÙYHØ][Ù×İ[™\šYšYYÙØ[YWÛX]Ú\×İŒŠ	‹	š[RY
NÂˆ	Ø[™Y]\ÈH×NÂˆ›Ü™XXÚ
	˜[šÙY\È	›İÊHÂˆYˆ

[
I›İÖÉÚ[\ÜØÛİ[	×HJHÂˆÛÛ[YNÂˆBˆ	X]ÚY]ÈH×NÂˆYˆ

[
I›İÖÉÙ^XİÛØš™XİÛX]Ú\É×Hˆ
HÂˆ	X]Ú\ÈHØ][Ù×Ø[
ˆ	‹ˆ	ÔÑSPÕTÕSÕœ™\]Z\™YÛØš™XİÜ]	Âˆˆ	È”“ÓHYWÙ\[™[˜ÚY\È	Âˆˆ	È“ÒSˆYWÙš[\ÈİÛ™\ˆÓˆİÛ™\‹šYY™š[WÚYS‘İÛ™\‹œØØ[—Üİ]\ÏH™\šYšYY‰Âˆˆ	È“ÒSˆYWÙ^ÜÈ]Y]YYÙ^ÜÓˆ]Y]YYÙ^Ü™š[WÚYOÈS‘ÕÑTŠ]Y]YYÙ^Ü™[Ü]
OSÕÑTŠœ™\]Z\™YÛØš™XİÜ]
IÂˆˆ	ÈÒT‘HİÛ™\‹™Ø[YWÚYOÈS‘ÕÑTŠœ™\]Z\™YÜXÚØYÙJOSÕÑTŠÊHäCXv·ªº*Şv†ãyËijØK Â×gâ•âŠ{k£™è¥§$jjg¦j×!yÓÚ¶