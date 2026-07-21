<?php
declare(strict_types=1);

/**
 * UE3 .upk files are Unreal package containers. Their internal exports are
 * serialized UObject records, not independent child package files. The catalog
 * therefore exposes and links the parsed exports while retaining/downloading
 * the original .upk as the installable container.
 */

function catalog_upk_engine_major(string $engineKey): int
{
    return preg_match('/UE\s*([0-9]+)/i', trim($engineKey), $match) === 1
        ? (int)$match[1]
        : 0;
}

function catalog_upk_supported_engine(string $engineKey): bool
{
    return catalog_upk_engine_major($engineKey) === 3;
}

function catalog_upk_export_target(int $exportIndex): string
{
    return 'export-' . max(0, $exportIndex);
}

function catalog_upk_export_href(int $fileId, int $exportIndex): string
{
    $target = catalog_upk_export_target($exportIndex);
    return 'file-examine.php?id=' . max(1, $fileId)
        . '&tab=exports&target=' . rawurlencode($target)
        . '#' . rawurlencode($target);
}
