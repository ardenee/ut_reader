<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Exposes the standalone `UE5` package upload route through the shared standalone UI helper.
 * Why: It preserves the existing parser-development URL without duplicating upload validation and presentation code.
 * Role: Thin engine-specific adapter that supplies the `UE5` label and allowed extensions.
 * Audit: Keep engine-specific configuration here; generic standalone upload behavior belongs in the shared helper.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/standalone/StandalonePackageUi.php';

\UtReader\Standalone\StandalonePackageUi::renderUploadPage('UE5', __DIR__, ['uasset', 'umap', 'uexp']);
