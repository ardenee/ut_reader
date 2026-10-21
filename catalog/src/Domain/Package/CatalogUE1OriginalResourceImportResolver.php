<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Domain\Package;

use InvalidArgumentException;

/**
 * Epic v0.82/v0.83/v0.84a UnRes.cpp::AddFile/PrivateLookup/FindResource.
 *
 * 1996 resource imports are resolved BEFORE the new file's exports exist in
 * the global resource manager. The key is (EResourceType, case-insensitive
 * resource name), never modern package/class/outer indices. Source ignores
 * ResVersionNumber and serialized HeaderSize for this lookup.
 *
 * A file catalogue is NOT the engine's already-loaded ResArray. Without a
 * captured, ordered runtime registry the result is always not_evaluated.
 */
final class CatalogUE1OriginalResourceImportResolver
{
    /**
     * @param list<array<string,mixed>> $imports Source FResNameFileEntry rows
     * @param list<array<string,mixed>>|null $loadedResources Optional ordered
     *     snapshot of already-loaded ResArray slots, not another file's exports.
     * @return list<array<string,mixed>>
     */
    public static function project(array $imports, ?array $loadedResources = null): array
    {
        $results = [];
        foreach ($imports as $index => $import) {
            $name = (string)($import['name'] ?? '');
            $type = (int)($import['resource_type'] ?? -1);
            if (!array_key_exists('name', $import) || !array_key_exists('resource_type', $import)) {
                throw new InvalidArgumentException('Original resource import lacks its native name/type.');
            }

            $result = [
                'import_index' => (int)($import['index'] ?? $index),
                'resource_name' => $name,
                'resource_type' => $type,
                'lookup_contract' => 'global_loaded_resarray_type_and_name',
                'runtime_object_loaded' => null,
                'loaded_resource_slot' => null,
            ];
            if ($loadedResources === null) {
                $result['status'] = 'runtime_registry_not_available';
                $results[] = $result;
                continue;
            }

            // FindResource walks ResArray from lowest slot to highest.
            // Retain caller-provided slot order; do not select by filename,
            // export index, package path or resource version.
            $result['status'] = 'not_found_in_supplied_registry';
            foreach ($loadedResources as $slot => $candidate) {
                if ($candidate === null) {
                    // Empty ResArray slots are skipped by native FindResource.
                    continue;
                }
                if (!is_array($candidate)
                    || !array_key_exists('name', $candidate)
                    || !array_key_exists('resource_type', $candidate)) {
                    throw new InvalidArgumentException('Loaded-resource registry contains an invalid slot.');
                }
                if ((int)$candidate['resource_type'] === $type
                    && strcasecmp((string)$candidate['name'], $name) === 0) {
                    $result['status'] = 'matched_supplied_registry';
                    $result['loaded_resource_slot'] = (int)$slot;
                    break;
                }
            }
            $results[] = $result;
        }
        return $results;
    }
}
