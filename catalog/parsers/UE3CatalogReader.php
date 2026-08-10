<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Loads the strict Epic UE3 package reader used by the catalog.
 * Why: UE3 catalog parsing must follow Epic's serialized package format directly instead of runtime source rewriting or layout guessing.
 * Role: UE3 parser entry point used by the catalog reader-resolution path.
 */
declare(strict_types=1);

if (class_exists('CatalogUE3PackageReader', false)) {
    return;
}

require_once __DIR__ . '/EpicUE3PackageReader.php';

if (!class_exists('CatalogUE3PackageReader', false)) {
    throw new RuntimeException('Epic UE3 catalog parser failed to define CatalogUE3PackageReader.');
}
