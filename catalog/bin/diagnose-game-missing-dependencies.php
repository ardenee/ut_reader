#!/usr/bin/env php
<?php
/**
 * Purpose: Classifies a game's current missing-dependency count without changing catalog data.
 * Why: A large missing-object count can mean genuinely absent packages, object-path mismatches,
 *      or a broken compact projection/resolver. These cases must be distinguished before repair.
 * Role: Read-only CLI diagnostic for Full Sync and compact dependency resolution.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$options = getopt('', ['game:', 'top::', 'samples::']);
$gameArg = trim((string)($options['game'] ?? ''));
$top = max(1, min(50, (int)($options['top'] ?? 15)));
$samples = max(1, min(30, (int)($options['samples'] ?? 10)));

if ($gameArg === '') {
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-game-missing-dependencies.php --game=\"Unreal Tournament 2003\" [--top=15] [--samples=10]\n");
    exit(1);
}

require_once $root . '/bootstrap.php';
$application = catalog_bootstrap();
$db = $application->db;

$one = static function (PDO $db, string $sql, array $args = []): array {
    $statement = $db->prepare($sql);
    $statement->execute($args);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
};
$all = static function (PDO $db, string $sql, array $args = []): array {
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
};
$scalar = static function (PDO $db, string $sql, array $args = []): int {
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return (int)($statement->fetchColumn() ?: 0);
};

if (ctype_digit($gameArg)) {
    $game = $one($db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [(int)$gameArg]);
} else {
    $game = $one(
        $db,
        'SELECT id,name,slug FROM ue_games WHERE name=? OR slug=? ORDER BY (name=?) DESC,id LIMIT 1',
        [$gameArg, $gameArg, $gameArg]
    );
}
if ($game === []) {
    fwrite(STDERR, 'Game not found: ' . $gameArg . PHP_EOL);
    exit(2);
}
$gameId = (int)$game['id'];

$verifiedFiles = $scalar(
    $db,
    'SELECT COUNT(*) FROM ue_files WHERE game_id=? AND scan_status="verified"',
    [$gameId]
);
$metadataTotals = $one(
    $db,
    'SELECT COUNT(*) metadata_files,COALESCE(SUM(m.import_count),0) import_count,'
    . 'COALESCE(SUM(m.export_count),0) export_count '
    . 'FROM ue_file_metadata m JOIN ue_files f ON f.id=m.file_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND m.format_version=2',
    [$gameId]
);
$linkTotals = $one(
    $db,
    'SELECT COUNT(*) dependency_rows,'
    . 'COALESCE(SUM(l.status=0),0) missing_count,'
    . 'COALESCE(SUM(l.status=1),0) resolved_count,'
    . 'COALESCE(SUM(l.status=2),0) package_only_count,'
    . 'COALESCE(SUM(l.status=3),0) common_count '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified"',
    [$gameId]
);
$summaryTotals = $one(
    $db,
    'SELECT COALESCE(SUM(dependency_count),0) dependency_count,'
    . 'COALESCE(SUM(missing_count),0) missing_count,'
    . 'COUNT(DISTINCT CASE WHEN missing_count>0 THEN required_package END) package_names_with_missing '
    . 'FROM ue_dependency_package_summaries WHERE game_id=?',
    [$gameId]
);

$dependencyProjectionMismatchFiles = $scalar(
    $db,
    'SELECT COUNT(*) FROM ('
    . 'SELECT f.id,m.import_count,COUNT(l.file_id) actual_count '
    . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
    . 'LEFT JOIN ue_dependency_links l ON l.file_id=f.id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" '
    . 'GROUP BY f.id,m.import_count HAVING m.import_count<>COUNT(l.file_id)'
    . ') x',
    [$gameId]
);
$exportProjectionMismatchFiles = $scalar(
    $db,
    'SELECT COUNT(*) FROM ('
    . 'SELECT f.id,m.export_count,COUNT(l.file_id) actual_count '
    . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
    . 'LEFT JOIN ue_export_lookup l ON l.file_id=f.id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" '
    . 'GROUP BY f.id,m.export_count HAVING m.export_count<>COUNT(l.file_id)'
    . ') x',
    [$gameId]
);

$packageExpr = '(CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci)';
$providerExists = 'EXISTS ('
    . 'SELECT 1 FROM ue_package_providers p '
    . 'JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id '
    . 'WHERE p.game_id=owner.game_id AND pf.scan_status="verified" '
    . 'AND p.package_name=' . $packageExpr
    . ')';
$providerWithExactExport = 'EXISTS ('
    . 'SELECT 1 FROM ue_package_providers p '
    . 'JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id '
    . 'JOIN ue_export_lookup el ON el.file_id=p.file_id AND el.path_hash=l.required_path_hash '
    . 'WHERE p.game_id=owner.game_id AND pf.scan_status="verified" '
    . 'AND p.package_name=' . $packageExpr
    . ')';
$missingBase = 'FROM ue_dependency_links l '
    . 'JOIN ue_files owner ON owner.id=l.file_id '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE owner.game_id=? AND owner.scan_status="verified" AND l.status=0 ';

$overflowPackageTerms = $scalar(
    $db,
    'SELECT COUNT(*) ' . $missingBase . 'AND pkg.is_overflow=1',
    [$gameId]
);
$missingWithoutProvider = $scalar(
    $db,
    'SELECT COUNT(*) ' . $missingBase . 'AND pkg.is_overflow=0 AND NOT ' . $providerExists,
    [$gameId]
);
$missingWithProvider = $scalar(
    $db,
    'SELECT COUNT(*) ' . $missingBase . 'AND pkg.is_overflow=0 AND ' . $providerExists,
    [$gameId]
);
$missingWithExactExport = $scalar(
    $db,
    'SELECT COUNT(*) ' . $missingBase . 'AND pkg.is_overflow=0 AND ' . $providerWithExactExport,
    [$gameId]
);
$missingWithProviderNoExactExport = max(0, $missingWithProvider - $missingWithExactExport);

$topPackages = $all(
    $db,
    'SELECT required_package,SUM(missing_count) missing_count,COUNT(*) owner_files '
    . 'FROM ue_dependency_package_summaries '
    . 'WHERE game_id=? AND missing_count>0 '
    . 'GROUP BY required_package ORDER BY missing_count DESC,required_package ASC LIMIT ' . $top,
    [$gameId]
);
$providerCountStatement = $db->prepare(
    'SELECT COUNT(*) FROM ue_package_providers p '
    . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id '
    . 'WHERE p.game_id=? AND f.scan_status="verified" AND p.package_name=?'
);
foreach ($topPackages as &$row) {
    $providerCountStatement->execute([$gameId, (string)$row['required_package']]);
    $row['verified_provider_rows'] = (int)($providerCountStatement->fetchColumn() ?: 0);
    $row['missing_count'] = (int)$row['missing_count'];
    $row['owner_files'] = (int)$row['owner_files'];
}
unset($row);

$sampleSelect = 'SELECT owner.id owner_file_id,owner.original_name owner_file,owner.package_name owner_package,'
    . $packageExpr . ' required_package,'
    . '(CONVERT(obj.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci) required_object_path,'
    . 'l.import_index,HEX(l.required_path_hash) required_path_hash ';
$sampleJoins = 'FROM ue_dependency_links l '
    . 'JOIN ue_files owner ON owner.id=l.file_id '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'JOIN ue_terms obj ON obj.id=l.required_object_term_id '
    . 'WHERE owner.game_id=? AND owner.scan_status="verified" AND l.status=0 AND pkg.is_overflow=0 ';

$sampleNoProvider = $all(
    $db,
    $sampleSelect . $sampleJoins . 'AND NOT ' . $providerExists
    . ' ORDER BY required_package,owner_file,l.import_index LIMIT ' . $samples,
    [$gameId]
);
$sampleProviderNoObject = $all(
    $db,
    $sampleSelect . $sampleJoins . 'AND ' . $providerExists . ' AND NOT ' . $providerWithExactExport
    . ' ORDER BY required_package,owner_file,l.import_index LIMIT ' . $samples,
    [$gameId]
);
$sampleResolverMismatch = $all(
    $db,
    $sampleSelect . $sampleJoins . 'AND ' . $providerWithExactExport
    . ' ORDER BY required_package,owner_file,l.import_index LIMIT ' . $samples,
    [$gameId]
);

$interpretation = [];
if ($dependencyProjectionMismatchFiles > 0 || $exportProjectionMismatchFiles > 0) {
    $interpretation[] = 'Projection integrity is not clean: one or more compact lookup row counts do not match authoritative metadata counts.';
}
if ($missingWithExactExport > 0) {
    $interpretation[] = 'Definite resolver inconsistency: some dependencies are marked missing even though a current package provider has an export with the exact required path hash.';
}
if ($missingWithProviderNoExactExport > 0) {
    $interpretation[] = 'These are not missing package files: a verified provider exists, but the requested object path did not match an exact current export hash. Inspect parser/path/version compatibility before calling them genuine missing dependencies.';
}
if ($missingWithoutProvider > 0) {
    $interpretation[] = 'These dependency rows have no current verified provider for the required package name and are the strongest candidates for genuinely absent package files.';
}
if ((int)($linkTotals['missing_count'] ?? 0) === 0) {
    $interpretation[] = 'No current missing dependency rows were found for this game.';
}

$result = [
    'ok' => true,
    'read_only' => true,
    'game' => [
        'id' => $gameId,
        'name' => (string)$game['name'],
        'slug' => (string)$game['slug'],
    ],
    'totals' => [
        'verified_files' => $verifiedFiles,
        'format2_metadata_files' => (int)($metadataTotals['metadata_files'] ?? 0),
        'metadata_import_count' => (int)($metadataTotals['import_count'] ?? 0),
        'metadata_export_count' => (int)($metadataTotals['export_count'] ?? 0),
        'dependency_rows' => (int)($linkTotals['dependency_rows'] ?? 0),
        'missing_dependencies' => (int)($linkTotals['missing_count'] ?? 0),
        'resolved_dependencies' => (int)($linkTotals['resolved_count'] ?? 0),
        'package_only_dependencies' => (int)($linkTotals['package_only_count'] ?? 0),
        'common_dependencies' => (int)($linkTotals['common_count'] ?? 0),
        'summary_dependency_count' => (int)($summaryTotals['dependency_count'] ?? 0),
        'summary_missing_count' => (int)($summaryTotals['missing_count'] ?? 0),
        'package_names_with_missing_objects' => (int)($summaryTotals['package_names_with_missing'] ?? 0),
    ],
    'projection_integrity' => [
        'files_with_dependency_link_count_mismatch' => $dependencyProjectionMismatchFiles,
        'files_with_export_lookup_count_mismatch' => $exportProjectionMismatchFiles,
    ],
    'missing_classification' => [
        'missing_with_no_verified_package_provider' => $missingWithoutProvider,
        'missing_with_verified_package_provider' => $missingWithProvider,
        'missing_with_provider_and_exact_export_hash' => $missingWithExactExport,
        'missing_with_provider_but_no_exact_export_hash' => $missingWithProviderNoExactExport,
        'missing_with_overflowed_package_term' => $overflowPackageTerms,
    ],
    'top_missing_package_names' => $topPackages,
    'samples' => [
        'no_verified_package_provider' => $sampleNoProvider,
        'provider_exists_object_not_exact_hash' => $sampleProviderNoObject,
        'resolver_inconsistency_exact_export_exists' => $sampleResolverMismatch,
    ],
    'interpretation' => $interpretation,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
