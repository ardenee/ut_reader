<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for file examine.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;

/**
 * Render serialized FName control bytes visibly without altering their stored
 * value or the database/reference lookups used by the examiner.
 *
 * Only text nodes are rewritten. Tags, attributes, JavaScript and CSS are left
 * byte-for-byte unchanged.
 */
function file_examine_render_opaque_controls(string $html, int &$replacementCount = 0): string
{
    $replacementCount = 0;
    $parts = preg_split(
        '/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<[^>]+>)/is',
        $html,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if ($parts === false) {
        return $html;
    }

    foreach ($parts as $index => $part) {
        if ($part === '' || str_starts_with($part, '<') || trim($part, " \t\n\r") === '') {
            continue;
        }
        $parts[$index] = preg_replace_callback(
            '/[\x00-\x09\x0B-\x1F\x7F]/',
            static function (array $match) use (&$replacementCount): string {
                $replacementCount++;
                return sprintf('\\x%02X', ord($match[0]));
            },
            $part
        ) ?? $part;
    }

    return implode('', $parts);
}

/** @param array{ok:bool,error:string,summary:array<string,mixed>,rows:list<array<string,mixed>>}|null $inspection */
function file_examine_header_html(?array $inspection): string
{
    if ($inspection === null) {
        return '';
    }
    $html = '<div class="card"><h2>Raw package header</h2>';
    if (!$inspection['ok']) {
        return $html . '<p class="muted">' . catalog_h($inspection['error']) . '</p></div>';
    }

    $html .= '<div class="two-col"><table>';
    $summary = $inspection['summary'];
    $left = ['GUID','Version','Licensee Version','Signature','Name Offset','Import Offset','Export Offset','Total Header Size'];
    $right = ['Flags','Build','Heritage','Counts','Catalog Counts','Generations','Folder Name'];
    foreach ($left as $label) {
        if (array_key_exists($label, $summary)) {
            $html .= '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$summary[$label]) . '</td></tr>';
        }
    }
    $html .= '</table><table>';
    foreach ($right as $label) {
        if (array_key_exists($label, $summary)) {
            $html .= '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$summary[$label]) . '</td></tr>';
        }
    }
    $html .= '</table></div>';

    if ($inspection['rows'] !== []) {
        $rows = array_slice($inspection['rows'], 0, 500);
        $html .= '<details><summary>Raw fields (' . count($inspection['rows']) . ')</summary><div class="examine-table-region"><table><thead><tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw hex</th><th>Note</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td class="mono">' . (int)$row['offset'] . '</td><td class="mono">' . (int)$row['size'] . '</td><td class="mono">' . catalog_h($row['field']) . '</td><td class="mono">' . catalog_h($row['type']) . '</td><td class="mono path">' . catalog_h($row['value']) . '</td><td class="mono path">' . catalog_h($row['hex']) . '</td><td>' . catalog_h($row['note']) . '</td></tr>';
        }
        $html .= '</tbody></table></div>';
        if (count($inspection['rows']) > count($rows)) {
            $html .= '<p class="muted">Only the first ' . count($rows) . ' raw header fields are displayed.</p>';
        }
        $html .= '</details>';
    }
    return $html . '</div>';
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id = $id === false || $id === null ? 0 : max(0, (int)$id);
$headerInspection = null;
if ($id > 0) {
    try {
        $config = catalog_config();
        $db = catalog_db($config);
        $row = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? LIMIT 1', [$id]);
        if ($row && (string)$row['scan_status'] === 'unverified') {
            header('Location: unverified-file-details.php?id=' . $id, true, 302);
            exit;
        }
        if ($row) {
            $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
            $storedPath = realpath(__DIR__ . '/' . (string)$row['relative_path']);
            if ($storageRoot && $storedPath && !str_starts_with($storedPath, $storageRoot)) {
                $storedPath = null;
            }
            $headerInspection = CatalogPackageHeaderInspector::inspect($storedPath ?: null, $row);
        }
    } catch (Throwable $error) {
        error_log('[UnrealDB file examiner routing] ' . $error->getMessage());
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ob_start();
require __DIR__ . '/file-examine-paged-core.php';
$html = (string)ob_get_clean();
$opaqueControlBytes = 0;
$html = file_examine_render_opaque_controls($html, $opaqueControlBytes);

if ($opaqueControlBytes > 0) {
    $notice = '<div class="card"><h2>Opaque serialized names</h2>'
        . '<p class="muted">This package contains FName values with control bytes. '
        . 'They are displayed as <span class="mono">\\xNN</span> escapes so they are visible. '
        . 'Their stored values and import/export reference identities have not been changed.</p></div>';
    $marker = '<div class="card"><h2>Package header</h2>';
    $html = str_replace($marker, $notice . $marker, $html);
}

$headerHtml = file_examine_header_html($headerInspection);
if ($headerHtml !== '') {
    $html = str_replace('<div class="card" id="package-tables">', $headerHtml . '<div class="card" id="package-tables">', $html);
}

echo $html;
