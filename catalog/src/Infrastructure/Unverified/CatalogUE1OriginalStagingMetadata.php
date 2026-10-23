<?php
/**
 * Native original UE1 metadata adapter for *unverified* staging.
 *
 * Epic original 1996 FResNameFileEntry resource tables are not classic
 * FObjectImport/FObjectExport. Original 1997 UObjectBase rows similarly lack
 * modern OuterIndex and package paths. Preserve them as source-typed records
 * instead of manufacturing paths, object references or successful parsing.
 *
 * Source of truth: original Unreal v0.82/0.83/0.84a UnRes.cpp/UnResTyp.h and
 * v0.86x/v0.867 UnLinker.h/UnObj.cpp.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceHeaderReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileObjectBaseReader;

final class CatalogUE1OriginalStagingMetadata
{
    /** @param array<string,mixed> $identity @return array<string,mixed> */
    public static function decode(string $path, array $identity): array
    {
        $format = (string)($identity['format'] ?? '');
        $version = (int)($identity['version'] ?? 0);
        if ($format === 'early_unreal_resource' && in_array($version, [9, 10], true)) {
            $raw = CatalogUE1OriginalResourceHeaderReader::read($path);
            $imports = $raw['imports'];
            $exports = $raw['exports'];
            $native = [
                'source_record_family' => 'FResNameFileEntry',
                'summary' => $raw['summary'],
                'resource_imports' => $imports,
                // Epic resolves these against the *already-loaded* global
                // ResArray. A catalog file alone cannot establish that state.
                // Persist the native lookup keys without a fabricated match.
                'resource_import_lookup' => \UnrealDb\Catalog\Domain\Package\CatalogUE1OriginalResourceImportResolver::project($imports),
                'resource_exports' => $exports,
                'resource_headers' => $raw['resource_headers'],
                'resource_database_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceDatabaseReader::read($path, false),
                'resource_texture_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceTextureReader::read($path, false),
                'resource_sound_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceSoundReader::read($path, false),
                'resource_font_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceFontReader::read($path, false),
                'resource_geometry_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceGeometryReader::read($path, false),
                'resource_meshmap_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceMeshMapReader::read($path, false),
                'resource_script_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceScriptReader::read($path, false),
                'resource_mesh_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceMeshReader::read($path, false),
                'resource_bsp_surface_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspSurfsReader::read($path, false),
                'resource_bsp_node_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspNodesReader::read($path, false),
                'resource_polygon_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourcePolysReader::read($path, false),
                'resource_lightmesh_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceLightMeshReader::read($path, false),
                'resource_bsp_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspReader::read($path, false),
                'resource_polys_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourcePolysReader::read($path, false),
                'resource_lightmesh_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceLightMeshReader::read($path, false),
                'resource_model_level_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceModelLevelReader::read($path, false),
                'resource_class_property_tables' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceClassPropertyReader::read($path, false),
                'resource_actor_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceActorReader::read($path, false),
                'resource_simple_database_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceSimpleDatabaseReader::read($path, false),
                'unconsumed_header_bytes' => $raw['unconsumed_header_bytes'],
                'payload_decoded' => false,
            ];
            $counts = ['names' => count($raw['names']), 'imports' => count($imports), 'exports' => count($exports)];
        } elseif ($format === 'early_unrealfile' && in_array($version, [22, 23], true)) {
            // Same serialized v22/v23 can originate from May or July 1997.
            // Never infer the build from this version number. Only an
            // explicitly caller-supplied, validated Epic source lineage
            // disambiguates otherwise compatible native/bytecode archives.
            $sourceLineage = $identity['source_lineage'] ?? null;
            if ($sourceLineage !== null) {
                if (!is_string($sourceLineage)) {
                    throw new RuntimeException('Original Unrealfile source lineage must be a string');
                }
                \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate::validate($sourceLineage);
            }
            $raw = CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
            $externalDiscovery = \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileExternalSchemaResolver::discoverSiblingProviders($path);
            $objects = $raw['object_bases'];
            $imports = array_values(array_filter($objects, static fn(array $r):bool => $r['kind'] === 'import'));
            $exports = array_values(array_filter($objects, static fn(array $r):bool => $r['kind'] === 'export'));
            $native = [
                'source_record_family' => 'UObjectBase',
                'source_lineage_provenance' => $sourceLineage === null ? null : 'caller_explicit',
                'source_lineage_provided' => $sourceLineage,
                'summary' => $raw['summary'],
                'declared_dependencies' => $raw['dependencies'],
                'external_class_dependency_discovery' => $externalDiscovery['diagnostics'],
                'external_class_provider_names' => array_keys($externalDiscovery['providers']),
                'object_bases' => $objects,
                'unrealfile_class_schemas' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileClassSchemaReader::read($path, false),
                'unrealfile_binary_properties' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader::read($path, false, $externalDiscovery['providers'], $sourceLineage),
                'unrealfile_database_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileDatabaseDataReader::read($path, false),
                'unrealfile_level_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileLevelReader::read($path, false, $sourceLineage),
                'unrealfile_primitive_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePrimitiveHeaderReader::read($path, false),
                'unrealfile_mesh_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileMeshHeaderReader::read($path, false, $sourceLineage),
                'unrealfile_model_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileModelHeaderReader::read($path, false, $sourceLineage),
                'unrealfile_texture_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileTextureHeaderReader::read($path, false, $sourceLineage),
                'unrealfile_special_headers' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileSpecialHeadersReader::read($path, false),
                'unrealfile_bsp_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBspRecordsReader::read($path, false, $sourceLineage),
                'unrealfile_lightmesh_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileLightMeshDataReader::read($path, false),
                'unrealfile_polygon_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePolysDataReader::read($path, false),
                // A caller-confirmed May/July source lineage must reach the
                // FStackNode branch reader; both source trees use v22/v23.
                'unrealfile_stacktree_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileStackTreeDataReader::read($path, false, $sourceLineage),
                'unrealfile_audio_records' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileAudioReader::read($path, false),
                'unrealfile_bit_archive' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBitArrayReader::read($path, false),
                'unrealfile_properties_bin' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertiesReader::read($path, false, $externalDiscovery['providers'], $sourceLineage),
                'unrealfile_script_expressions' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileScriptExpressionReader::read($path, false, $sourceLineage),
                'serialized_stack_prefixes' => \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileStackReader::read($path, false),
                'payload_decoded' => false,
            ];
            $counts = ['names' => count($raw['names']), 'imports' => count($imports), 'exports' => count($exports)];
        } else {
            throw new RuntimeException(
                (string)($identity['reason'] ?? ('No source-backed native metadata adapter for '
                    . ($format !== '' ? $format : 'unknown original UE1 format')
                    . ', serialized version ' . $version . '.'))
            );
        }

        // O06: source-typed byte boundaries/digests. Actual class member
        // values are NOT yet decoded; do not mark the staged file verified.
        $native['serialized_object_ranges'] = \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalSerializedObjectReader::read($path, $format);
        $names = array_map(
            static fn(array $r):array => [
                'name' => (string)$r['name'],
                'flags' => (int)$r['flags'],
                'serialized_offset' => (int)$r['serialized_offset'],
            ],
            $raw['names']
        );
        if (count($names) !== $counts['names']) {
            throw new RuntimeException('Original UE1 native name table cardinality mismatch.');
        }
        $native['serialized_format'] = $format;
        $native['serialized_version'] = $version;
        $native['counts'] = $counts;
        return [
            'header' => [
                'version' => $version,
                'original_format' => $format,
                'native_counts' => $counts,
                'full_package_supported' => false,
                'native_metadata_decoded' => true,
            ],
            // Names retain source index identity. Native import/export tables
            // must NOT enter modern package snapshot columns.
            'names' => $names,
            'imports' => [],
            'exports' => [],
            'native_metadata' => $native,
            'parse_complete' => false,
            'notes' => [
                'Epic original UE1 format ' . $format . ' version ' . $version
                    . ': native metadata decoded; class/resource payload parsing not implemented.',
                'Native original records are retained separately; modern object paths and outer references were not invented.',
            ],
        ];
    }
}
