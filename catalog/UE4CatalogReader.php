<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Loads the standalone UE4 package reader and exposes its `UnrealPackageReader4` class under the generic `UnrealPackageReader` name expected by catalog callers.
 * Why: It bridges the engine-specific UE4 reader into older catalog code without copying the reader implementation.
 * Role: Compatibility adapter between `UE4/UnrealPackageReader.php` and catalog code that still expects the generic reader class name.
 * Audit: Keep thin; remove only after all callers use the UE4-specific/namespaced reader directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/../UE4/UnrealPackageReader.php';

if (!class_exists('UnrealPackageReader4', false)) {
    throw new RuntimeException('UE4 reader loaded, but UnrealPackageReader4 was not defined.');
}

if (!class_exists('UnrealPackageReader', false)) {
    class_alias('UnrealPackageReader4', 'UnrealPackageReader');
}
