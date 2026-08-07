<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for file examine export.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = max(0, (int)($_GET['id'] ?? 0));
    $table = PdoPackageTablePageQuery::normalizeTable((string)($_GET['table'] ?? 'names'));
    $format = strtolower(trim((string)($_GET['format'] ?? 'csv')));
    if (!in_array($format, ['csv', 'json'], true)) {
        throw new RuntimeException('Unsupported export format.');
    }

    $file = catalog_one(
        $db,
        'SELECT id,package_name,original_name,scan_status,name_count,import_count,export_count '
        . 'FROM ue_files WHERE id=?',
        [$fileId]
    );
    if (!$file || (string)$file['scan_status'] !== 'verified') {
        throw new RuntimeException('Verified file not found.');
    }

    $definition = PdoPackageTablePageQuery::definition($table);
    $columns = $definition['columns'];
    $safePackage = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$file['package_name']) ?: 'package';
    $filename = $safePackage . '-' . $table . '.' . $format;

    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/json; charset=UTF-8'));

    $pageSize = 1000;
    $pageNumber = 1;

    if ($format === 'csv') {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Could not open export output.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $columns, ',', '"', '');
        do {
            $page = PdoPackageTablePageQuery::fetchPage(
                $db,
                $file,
                $table,
                $pageNumber,
                $pageSize
            );
            foreach ($page['rows'] as $row) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = $row[$column] ?? null;
                }
                fputcsv($output, $values, ',', '"', '');
            }
            fflush($output);
            $pageNumber++;
        } while ($pageNumber <= (int)$page['pages']);
        fclose($output);
        exit;
    }

    echo '{"file_id":' . $fileId
        . ',"package_name":' . json_encode((string)$file['package_name'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        . ',"table":' . json_encode($table, JSON_THROW_ON_ERROR)
        . ',"rows":[';
    $first = true;
    do {
        $page = PdoPackageTablePageQuery::fetchPage(
            $db,
            $file,
            $table,
            $pageNumber,
            $pageSize
        );
        foreach ($page['rows'] as $row) {
            if (!$first) {
                echo ',';
            }
            echo json_encode(
                $row,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
            $first = false;
        }
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
        $pageNumber++;
    } while ($pageNumber <= (int)$page['pages']);
    echo ']}';
} catch (Throwable $error) {
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'Export failed: ' . $error->getMessage();
}
