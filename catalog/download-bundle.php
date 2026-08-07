<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Redirects the legacy bundle-download route to `download-package.php` with dependency-ZIP options.
 * Why: It preserves older links while package download generation is handled by the consolidated download endpoint.
 * Role: Compatibility redirect only; actual bundle generation lives in the package download flow.
 * Audit: Thin compatibility route; removable after old callers/bookmarks are confirmed gone.
 */
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$query = http_build_query([
    'id' => $id,
    'format' => 'dependency_zip',
    'dependencies' => 1,
]);
header('Location: download-package.php?' . $query, true, 302);
exit;
