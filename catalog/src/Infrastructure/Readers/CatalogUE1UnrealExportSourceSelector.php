<?php
/**
 * Unreal game-source selection guard for conditional 227k FObjectExport data.
 *
 * The UE1 archive reader never guesses/retries a layout: its native branch
 * follows the compiled 227k operator. This separate game-aware layer checks
 * whether the selected native layout has uniquely defensible on-disk evidence.
 *
 * Version 69/licensee 227 is NOT executable provenance: UT99 can share it.
 * Only call this selector after choosing the Unreal game (not generic UE1).
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1UnrealExportSourceSelector
{
    public static function load(string $path): CatalogUE1PackageReader
    {
        // Explicit native 227k archive cursor rule, with Unreal summary/name
        // family selected by the caller's game identity.
        $native = new CatalogUE1PackageReader($path, true, true);
        if (!$native->sawNative227kArchetypeField()) {
            // No conditional archetype bytes were encountered. Other UE1
            // structural checks are returned to the ordinary caller.
            return $native;
        }

        // A diagnostic comparison, NOT an original 227k loader operation.
        // This alternate interpretation must not become a UE1 fallback.
        $withoutArchetype = new CatalogUE1PackageReader($path, true, false);
        if ($withoutArchetype->validatePackage() === []) {
            if ($native->validatePackage() === []) {
                throw new RuntimeException(
                    'Ambiguous Unreal v227 export archive: both layouts pass'
                    . ' the full source-defined table/reference checks.'
                );
            }
            throw new RuntimeException(
                'Unreal v227 RF_HasArchtype export requires a different'
                . ' source-profile serializer: 227k native layout failed,'
                . ' legacy layout parsed but its native provenance is unverified.'
            );
        }

        if ($native->validatePackage() === []) {
            $native->recordVerifiedNative227kArchetypeLayout();
        }
        return $native;
    }
}
