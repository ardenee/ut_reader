<?php
/** Deferred enrichment for the paged file examiner. */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;

header('Content-Type: application/json; charset=utf-8');

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = max(0, (int)($_GET['id'] ?? 0));
    $table = PdoPackageTablePageQuery::normalizeTable((string)($_GET['table'] ?? 'names'));
    $pageSize = PdoPackageTablePageQuery::normalizePageSize(max(0, (int)($_GET['page_size'] ?? PdoPackageTablePageQuery::DEFAULT_PAGE_SIZE)));
    $pageNumber = max(1, (int)($_GET['page'] ?? 1));

    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1', [$fileId]);
    if (!$file) {
        throw new RuntimeException('Verified file not found.');
    }

    $page = PdoPackageTablePageQuery::fetchPage($db, $file, $table, $pageNumber, $pageSize);
    $rows = $page['rows'];
    $payload = ['ok' => true, 'table' => $table, 'name_usage' => [], 'name_links' => [], 'dependencies' => []];

    if ($table === 'names') {
        $names = array_map(static fn(array $row): string => (string)($row['name_text'] ?? ''), $rows);
        $usage = PdoPackageTablePageQuery::nameUsage($db, $fileId, $names);
        foreach ($rows as $row) {
            $index = (int)($row['name_index'] ?? -1);
            $key = mb_strtolower(trim((string)($row['name_text'] ?? '')), 'UTF-8');
            $payload['name_usage'][(string)$index] = $usage[$key] ?? [
                'imports_count' => 0, 'imports_target' => '',
                'exports_count' => 0, 'exports_target' => '',
            ];
        }
    } elseif ($table === 'imports') {
        $values = [];
        foreach ($rows as $row) {
            foreach (['class_package','class_name','object_name','root_package'] as $column) {
                $values[] = (string)($row[$column] ?? '');
            }
        }
        $lookup = PdoPackageTablePageQuery::nameLookup($db, $fileId, $values);
        foreach ($rows as $row) {
            $index = (int)($row['import_index'] ?? -1);
            foreach (['class_package','class_name','object_name'] as $column) {
                $value = trim((string)($row[$column] ?? ''));
                $key = mb_strtolower($value, 'UTF-8');
                if ($value !== '' && isset($lookup[$key])) {
                    $payload['name_links'][(string)$index][$column] = (int)$lookup[$key];
                }
            }
        }
        $dependencyMap = PdoPackageTablePageQuery::dependencyMap($db, $fileId, $rows);
        foreach ($rows as $row) {
            $index = (int)($row['import_index'] ?? -1);
            $mapKey = (int)($row['id'] ?? ($index + 1));
            if (isset($dependencyMap[$mapKey])) {
                $payload['dependencies'][(string)$index] = $dependencyMap[$mapKey];
            }
        }
    } else {
        $values = [];
        foreach ($rows as $row) {
            foreach (['class_name','object_name'] as $column) {
                $values[] = (string)($row[$column] ?? '');
            }
        }
        $lookup = PdoPackageTablePageQuery::nameLookup($db, $fileId, $values);
        foreach ($rows as $row) {
            $index = (int)($row['export_index'] ?? -1);
            foreach (['class_name','object_name'] as $column) {
                $value = trim((string)($row[$column] ?? ''));
                $key = mb_strtolower($value, 'UTF-8');
                if ($value !== '' && isset($lookup[$key])) {
                    $payload['name_links'][(string)$index][$column] = (int)$lookup[$key];
                }
            }
        }
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}
