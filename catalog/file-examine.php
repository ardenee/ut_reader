<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

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

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$id = $id === false || $id === null ? 0 : max(0, (int)$id);
if ($id > 0) {
    try {
        $db = catalog_db(catalog_config());
        $row = catalog_one($db, 'SELECT scan_status FROM ue_files WHERE id=? LIMIT 1', [$id]);
        if ($row && (string)$row['scan_status'] === 'unverified') {
            header('Location: unverified-file-details.php?id=' . $id, true, 302);
            exit;
        }
    } catch (Throwable $error) {
        error_log('[UnrealDB file examiner routing] ' . $error->getMessage());
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

ob_start();
require __DIR__ . '/file-examine-core.php';
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

echo $html;
