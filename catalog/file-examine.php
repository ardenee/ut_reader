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
require_once __DIR__ . '/lib/CatalogFileFeedback.php';

use UnrealDb\Catalog\Application\Catalog\CatalogPackageHeaderInspector;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogVerifiedFileRenameService;

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

/**
 * @param array<string,mixed> $file
 * @param array<string,mixed>|null $flash
 * @param list<array{package_name:string,suggested_filename:string,matched_objects:int,referencing_files:int}> $suggestions
 */
function file_examine_admin_rename_html(array $file, ?array $flash, array $suggestions, bool $suggestionsRequested): string
{
    $fileId = (int)$file['id'];
    $html = '<div class="card"><h2>Correct filename / package identity</h2>';
    if (is_array($flash)) {
        $ok = !empty($flash['ok']);
        $html .= '<p class="' . ($ok ? '' : 'muted') . '"><strong>'
            . ($ok ? 'Saved:' : 'Rename failed:') . '</strong> '
            . catalog_h((string)($flash['message'] ?? '')) . '</p>';
    }
    $html .= '<p class="muted">Use this when an older cleanup/import rule changed characters in the logical filename. '
        . 'The catalogue filename and package name are corrected; the internal stored-file path is left unchanged. '
        . 'A durable dependency refresh is queued automatically so this provider and packages that require the corrected name are reconciled.</p>';
    $html .= '<form method="post" action="file-examine.php?id=' . $fileId . '">'
        . '<input type="hidden" name="action" value="rename_file">'
        . '<input type="hidden" name="id" value="' . $fileId . '">'
        . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('file_examine_rename')) . '">'
        . '<label>Filename<br><input class="mono" style="min-width:min(620px,100%)" name="new_original_name" maxlength="255" required value="'
        . catalog_h((string)$file['original_name']) . '"></label> '
        . '<button type="submit">Rename and refresh dependencies</button></form>';
    $html .= '<p class="muted small">Current package identity: <span class="mono">'
        . catalog_h((string)$file['package_name']) . '</span>.</p>';

    if (!$suggestionsRequested) {
        $html .= '<p><a class="button secondary" href="file-examine.php?id=' . $fileId
            . '&rename_suggestions=1">Find possible package-name mismatches</a></p>';
    } elseif ($suggestions === []) {
        $html .= '<p class="muted">No bounded rename candidates were found from unresolved imports whose object names are exported by this file.</p>';
    } else {
        $html .= '<h3>Possible names referenced by unresolved dependencies</h3>'
            . '<p class="muted small">These are hints, not automatic corrections. They are ranked by exported object names that overlap unresolved imports in the same game.</p>'
            . '<table><thead><tr><th>Referenced package</th><th>Matching objects</th><th>Referencing files</th><th>Suggested filename</th></tr></thead><tbody>';
        foreach ($suggestions as $suggestion) {
            $suggested = (string)$suggestion['suggested_filename'];
            $html .= '<tr><td class="mono">' . catalog_h((string)$suggestion['package_name']) . '</td>'
                . '<td>' . (int)$suggestion['matched_objects'] . '</td>'
                . '<td>' . (int)$suggestion['referencing_files'] . '</td>'
                . '<td class="mono">' . catalog_h($suggested) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    return $html . '</div>';
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id = $id === false || $id === null ? (int)($_POST['id'] ?? 0) : max(0, (int)$id);
$headerInspection = null;
$renameCardHtml = '';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_start_session();
    $isAdmin = catalog_support_is_admin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'rename_file') {
        if (!$isAdmin) {
            http_response_code(403);
            throw new RuntimeException('Administrator authentication is required to rename a verified file.');
        }
        catalog_check_csrf('file_examine_rename');
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : 0;
        try {
            $result = (new CatalogVerifiedFileRenameService($db, $config))->rename(
                $id,
                (string)($_POST['new_original_name'] ?? ''),
                $userId > 0 ? $userId : null
            );
            $workerState = (new CatalogQueueWorkerStarter($db, $config))->start(
                trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog',
                !empty($result['changed']),
                $userId > 0 ? $userId : null
            );
            $message = !empty($result['changed'])
                ? (string)$result['old_original_name'] . ' → ' . (string)$result['new_original_name']
                    . '. Dependency refresh job #' . (int)$result['dependency_job_id'] . ' queued.'
                : 'The requested filename already matches the current catalogue identity.';
            $workerError = trim((string)($workerState['worker_error'] ?? ''));
            if ($workerError !== '') {
                $message .= ' The job remains durable, but worker start reported: ' . $workerError;
            }
            $_SESSION['file_examine_rename_flash'][$id] = ['ok' => true, 'message' => $message];
        } catch (Throwable $error) {
            $_SESSION['file_examine_rename_flash'][$id] = [
                'ok' => false,
                'message' => trim($error->getMessage()) !== '' ? trim($error->getMessage()) : 'Unknown rename error.',
            ];
        }
        header('Location: file-examine.php?id=' . $id, true, 303);
        exit;
    }

    $row = $id > 0 ? catalog_one($db, 'SELECT * FROM ue_files WHERE id=? LIMIT 1', [$id]) : null;
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

        if ($isAdmin && (string)$row['scan_status'] === 'verified') {
            $flash = is_array($_SESSION['file_examine_rename_flash'][$id] ?? null)
                ? $_SESSION['file_examine_rename_flash'][$id]
                : null;
            unset($_SESSION['file_examine_rename_flash'][$id]);
            $suggestionsRequested = (string)($_GET['rename_suggestions'] ?? '') === '1';
            $suggestions = $suggestionsRequested
                ? (new CatalogVerifiedFileRenameService($db, $config))->possiblePackageNames($id)
                : [];
            $renameCardHtml = file_examine_admin_rename_html($row, $flash, $suggestions, $suggestionsRequested);
        }
    }
} catch (Throwable $error) {
    error_log('[UnrealDB file examiner routing] ' . $error->getMessage());
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ob_start();
require __DIR__ . '/file-examine-paged-core.php';
$html = (string)ob_get_clean();
$opaqueControlBytes = 0;
$html = file_examine_render_opaque_controls($html, $opaqueControlBytes);

$feedbackCardHtml = is_array($row ?? null) ? catalog_file_feedback_form_html($id) : '';
if ($feedbackCardHtml !== '') {
    $marker = '<div class="card"><h2>Package header</h2>';
    $html = str_replace($marker, $feedbackCardHtml . $marker, $html);
}

if ($opaqueControlBytes > 0) {
    $notice = '<div class="card"><h2>Opaque serialized names</h2>'
        . '<p class="muted">This package contains FName values with control bytes. '
        . 'They are displayed as <span class="mono">\\xNN</span> escapes so they are visible. '
        . 'Their stored values and import/export reference identities have not been changed.</p></div>';
    $marker = '<div class="card"><h2>Package header</h2>';
    $html = str_replace($marker, $notice . $marker, $html);
}

if ($renameCardHtml !== '') {
    $marker = '<div class="card"><h2>Package header</h2>';
    $html = str_replace($marker, $renameCardHtml . $marker, $html);
}

$headerHtml = file_examine_header_html($headerInspection);
if ($headerHtml !== '') {
    $html = str_replace('<div class="card" id="package-tables">', $headerHtml . '<div class="card" id="package-tables">', $html);
}

echo $html;
