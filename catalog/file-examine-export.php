<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageTablePageService;

try {
    $db = catalog_db(catalog_config());
    $fileId = max(0, (int)($_GET['id'] ?? 0));
    $table = CatalogPackageTablePageService::normalizeTable((string)($_GET['table'] ?? 'names'));
    $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'json'], true)) {
        throw new RuntimeException('Unsupported export format.');
    }

    $file = catalog_one($db, 'SELECT id,package_name,original_name,scan_status FROM ue_files WHERE id=?', [$fileId]);
    if (!$file || (string)$file['scan_status'] !== 'verified') {
        throw new RuntimeException('Verified file not found.');
    }

    $definition = CatalogPackageTablePageService::definition($table);
    $columns = $definition['columns'];
    $indexColumn = $definition['index_column'];
    $safePackage = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$file['package_name']) ?: 'package';
    $filename = $safePackage . '-' . $table . '.' . $format;

    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8'));

    $batchSize = 1000;
    $after = -1;
    $statement = $db->prepare(
        'SELECT ' . implode(',', $columns)
        . ' FROM ' . $definition['table']
        . ' WHERE file_id=? AND ' . $indexColumn . '>?'
        . ' ORDER BY ' . $indexColumn . ' LIMIT ' . $batchSize
    );

    if ($format === 'csv') {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not open export output.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $columns);
        do {
            $statement->execute([$fileId, $after]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $row[$column] ?? null;
                }
                fputcsv($output, $values);
                $after = (int)$row[$indexColumn];
            }
            fflush($output);
        } while (count($rows) === $batchSize);
        fclose($output);
        exit;
    }

    echo '{"file_id":' . $fileId
        . ',"package_name":' . json_encode((string)$file['package_name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        . ',"table":' . json_encode($table, JSON_THROW_ON_ERROR)
        . ',"rows":[';
    $first = true;
    do {
        $statement->execute([$fileId, $after]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if (!$first) {
                echo ',';
            }
            echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $first = false;
            $after = (int)$row[$indexColumn];
        }
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    } while (count($rows) === $batchSize);
    echo ']}';
} catch (Throwable $error) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Export failed: ' . $error->getMessage();
}
