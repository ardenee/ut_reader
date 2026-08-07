<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Redirects the historical catalog admin route to the current administrator dashboard.
 * Why: It preserves old bookmarks and links after administration moved to `dashboard.php`.
 * Role: Compatibility redirect only; it contains no administration business logic.
 * Audit: Keep while external links may still target `admin.php`; otherwise it can eventually be removed with a
 *        web-server redirect.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();

header('Location: dashboard.php');
exit;
