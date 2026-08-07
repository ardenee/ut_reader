<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the standalone `UE5` Unreal package reader and its supporting binary/package structures.
 * Why: It decodes `UE5` package formats for parser development and for catalog reader bridges that explicitly load
 *      it.
 * Role: Engine-specific parser/reference implementation; not itself a catalog UI page.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
declare(strict_types=1);

require_once __DIR__ . '/../UE4/UnrealPackageReader.php';

if (!class_exists('UnrealPackageReader5', false)) {
    class_alias('UnrealPackageReader4', 'UnrealPackageReader5');
}
