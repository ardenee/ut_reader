<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Exposes the standalone `UE3` parser directory index through the shared standalone UI helper.
 * Why: It preserves the existing developer route without carrying another copy of generic directory-listing HTML.
 * Role: Thin route adapter for legacy/reference parser tooling outside the supported `/catalog/` application.
 * Audit: Keep engine-specific behavior out of this wrapper; generic listing behavior belongs in the shared helper.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/standalone/StandalonePackageUi.php';

\UtReader\Standalone\StandalonePackageUi::renderDirectoryIndex(__DIR__);
