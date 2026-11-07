<?php
/**
 * Verifies real physical ExportMap targets of a classic UE2 ImportMap page.
 *
 * This proves exact source-record navigation candidates only, not which
 * provider the original engine mounts or VerifyImport success.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/catalog/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoClassicImportExportPathLookup;

function requires(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$config = catalog_config();
$db = catalog_db($config);
$db->exec('SET TRANSACTION READ ONLY');
$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT id,game_id,package_name,original_name,'
        .'import_count,export_count,extension FROM ue_files WHERE id=? AND scan_status="verified"');
    $stmt->execute([1253000]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    requires(is_array($file), 'Genuine Unreal II package #1253000 is unavailable');
    $page = PdoPackageTablePageQuery::fetchPage($db, $file, 'imports', 1, 250);
    requires(count($page['rows']) === 250, 'Original 250 import page incomplete');
    $roots = array_values(array_unique(array_filter(array_map(
        static fn(array $row): string => trim((string)($row['root_package'] ?? '')),
        $page['rows']
    ))));
    $providers = PdoPackageTablePageQuery::packageFiles($db, 2, $roots);
    $started = microtime(true);
    $matches = PdoClassicImportExportPathLookup::match($db, 2, $page['rows'], $providers);
    $millis = round((microtime(true) - $started) * 1000, 2);
    $expected = [
        1 => [[111198,6548],[149518,10183]],
        4 => [[111173,169]],
        8 => [[111173,168]],
        20 => [[111167,6]],
        21 => [[111167,3]],
        27 => [[111198,10006],[149518,10110]],
        40 => [[111140,14]],
        41 => [[111140,425]],
        47 => [[111203,914]],
        57 => [[111139,142]],
        59 => [[111139,47]],
    ];
    foreach ($expected as $sourceImport => $wanted) {
        $actual = array_map(
            static fn(array $m): array => [(int)$m['file_id'],(int)$m['export_index']],
            $matches[$sourceImport] ?? []
        );
        requires($actual === $wanted,
            'Source Import #' . $sourceImport . ' has nonexact/missing physical ExportMap candidates: '
            . json_encode($actual));
    }
    foreach ([0,18,44,58] as $rootImport) {
        requires(empty($matches[$rootImport]),
            'A top-level package name must not link to an export inside itself: ' . $rootImport);
    }
    $legend = $matches[47] ?? [];
    requires(count($legend) === 1 && (int)$legend[0]['export_index'] === 914,
        'Same object leaf with wrong Outer chain was not rejected');

    $rowsByIndex = [];
    foreach ($page['rows'] as $row) {
        $rowsByIndex[(int)$row['import_index']] = $row;
    }
    $falsePath = $rowsByIndex[41];
    $falsePath['full_path'] = 'AtlantisT.Not_Actual.Floor';
    requires(PdoClassicImportExportPathLookup::match($db,2,[$falsePath],$providers) === [],
        'A matching leaf but wrong outer path must not create an export link');
    $numbered = $rowsByIndex[41];
    $numbered['derived_path_has_numbered_fname'] = true;
    requires(PdoClassicImportExportPathLookup::match($db,2,[$numbered],$providers) === [],
        'Nonzero FName Number requires exact-number identity; no guess allowed');
    $foreign = ['engine'=>[112300]];
    requires(PdoClassicImportExportPathLookup::match($db,2,[$rowsByIndex[1]],$foreign) === [],
        'Same-name foreign game provider must not be silently linked');

    $exactProvider = PdoPackageTablePageQuery::exportRowsByOriginalIndex(
        $db,111140,[425,14],'AtlantisT'
    );
    requires(($exactProvider[425]['full_path']??'')==='AtlantisT.Floor',
        'Export #425 does not serialize the AtlantisT.Floor path');
    requires(($exactProvider[14]['full_path']??'')==='AtlantisT.Floor.MetlFloor_U00W703v3',
        'Export #14 does not serialize the original nested path');
    $count = 0;
    foreach ($matches as $list) $count += count($list);
    $db->rollBack();
    echo json_encode([
        'pass' => true,
        'read_only' => true,
        'consumer_file_id' => 1253000,
        'consumer_game_id' => 2,
        'original_import_page_count' => count($page['rows']),
        'original_root_count' => count($roots),
        'imports_with_exact_export_candidates' => count($matches),
        'total_exact_export_candidates' => $count,
        'export_matching_elapsed_ms' => $millis,
        'sample_proven_export_rows' => $expected,
        'multiple_provider_candidates_retained' => true,
        'wrong_outer_path_rejected' => true,
        'numbered_fname_not_guessed' => true,
        'foreign_game_rejected' => true,
        'source_export_path_verified' => true,
        'runtime_provider_selected' => false,
        'db_mutations' => 0
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
}
