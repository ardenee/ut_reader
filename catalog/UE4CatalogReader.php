<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for UE4 catalog reader.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/../UE4/UnrealPackageReader.php';

if (!class_exists('UnrealPackageReader4', false)) {
    throw new RuntimeException('UE4 reader loaded, but UnrealPackageReader4 was not defined.');
}

if (!class_exists('UnrealPackageReader', false)) {
    class_alias('UnrealPackageReader4', 'UnrealPackageReader');
}
