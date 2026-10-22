#!/usr/bin/env php
<?php
/**
 * Read-only CLI inspector for Unreal package files and Unreal redirect wrappers.
 *
 * The command intentionally reuses the production redirect decoder and serialized
 * package-header routing logic. It does not write to the catalog database or
 * enqueue background jobs.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);

require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogRedirectArchive.php';
require_once $root . '/lib/GameProfiles.php';
require_once $root . '/lib/Scanner/CatalogScannerSupport.php';


/** @return array{bytes:int,zero_bytes:int,space_bytes:int,first_4096_zero_bytes:int,first_4096_space_bytes:int} */
function inspect_unreal_byte_profile(string $path): array
{
    $stream = @fopen($path, 'rb');
    if (!is_resource($stream)) {
        throw new RuntimeException('Could not open decoded package for byte-profile scan.');
    }

    $bytes = 0;
    $zeroBytes = 0;
    $spaceBytes = 0;
    $firstZeroBytes = 0;
    $firstSpaceBytes = 0;
    $firstRemaining = 4096;

    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $length = strlen($chunk);
            $bytes += $length;
            $zeroBytes += substr_count($chunk, "\x00");
            $spaceBytes += substr_count($chunk, "\x20");

            if ($firstRemaining > 0) {
                $prefix = substr($chunk, 0, $firstRemaining);
                $firstZeroBytes += substr_count($prefix, "\x00");
                $firstSpaceBytes += substr_count($prefix, "\x20");
                $firstRemaining -= strlen($prefix);
            }
        }
    } finally {
        fclose($stream);
    }

    return [
        'bytes' => $bytes,
        'zero_bytes' => $zeroBytes,
        'space_bytes' => $spaceBytes,
        'first_4096_zero_bytes' => $firstZeroBytes,
        'first_4096_space_bytes' => $firstSpaceBytes,
    ];
}

/**
 * @return array{status:string,reader_engine:string,issues:list<string>,validation_issues:array<int,mixed>,header:array<string,mixed>,name_count:?int,import_count:?int,export_count:?int}
 */
function inspect_unreal_full_validate(string $path, array $summary, ?array $corruption): array
{
    if ($corruption !== null) {
        return [
            'status' => 'FAILED',
            'reader_engine' => '',
            'issues' => ['Unreal package appears to have NUL bytes replaced with spaces throughout the payload.'],
            'validation_issues' => [[
                'code' => 'unreal.zero_to_space_corruption',
                'reason' => 'Unreal package appears to have NUL bytes replaced with spaces throughout the payload.',
                'arguments' => $corruption,
            ]],
            'header' => [],
            'name_count' => null,
            'import_count' => null,
            'export_count' => null,
        ];
    }

    if (empty($summary['ok'])) {
        return [
            'status' => 'FAILED',
            'reader_engine' => '',
            'issues' => [trim((string)($summary['reason'] ?? 'Invalid Unreal package header'))],
            'validation_issues' => [[
                'code' => trim((string)($summary['error_code'] ?? 'unreal.invalid_package')),
                'reason' => trim((string)($summary['reason'] ?? 'Invalid Unreal package header')),
                'arguments' => is_array($summary['error_arguments'] ?? null) ? $summary['error_arguments'] : [],
            ]],
            'header' => [],
            'name_count' => null,
            'import_count' => null,
            'export_count' => null,
        ];
    }

    $engine = strtoupper(trim((string)($summary['engine_hint'] ?? 'UNKNOWN'))) ?: 'UNKNOWN';
    if (!in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        return [
            'status' => 'FAILED',
            'reader_engine' => '',
            'issues' => ['Serialized Unreal package version is not mapped to a supported engine reader.'],
            'validation_issues' => [[
                'code' => 'unreal.unsupported_reader',
                'reason' => 'Serialized Unreal package version is not mapped to a supported engine reader.',
                'arguments' => [
                    'package_version' => $summary['version'] ?? null,
                    'licensee_version' => $summary['licensee'] ?? null,
                    'engine_hint' => $engine,
                ],
            ]],
            'header' => [],
            'name_count' => null,
            'import_count' => null,
            'export_count' => null,
        ];
    }

    try {
        $config = catalog_config();
        $readerClass = scanner_load_reader_class($config, $engine);
        $reader = new $readerClass($path);

        $issues = method_exists($reader, 'validatePackage')
            ? $reader->validatePackage()
            : (method_exists($reader, 'getDebugErrors') ? $reader->getDebugErrors() : []);
        [$fatal, $notes] = scanner_split_reader_issues(is_array($issues) ? $issues : []);
        $validationIssues = method_exists($reader, 'getValidationIssues')
            ? $reader->getValidationIssues()
            : [];

        if ($fatal !== []) {
            $validationIssues = is_array($validationIssues) ? $validationIssues : [];
            if (!is_array($validationIssues[0] ?? null)) {
                $classified = \UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier::classify(
                    (string)$fatal[0]
                );
                $validationIssues = [[
                    'code' => $classified['code'],
                    'reason' => $classified['reason'],
                    'arguments' => $classified['arguments'],
                ]];
            }

            return [
                'status' => 'FAILED',
                'reader_engine' => $engine,
                'issues' => array_values(array_map('strval', $fatal)),
                'validation_issues' => $validationIssues,
                'header' => method_exists($reader, 'getHeader') && is_array($reader->getHeader()) ? $reader->getHeader() : [],
                'name_count' => null,
                'import_count' => null,
                'export_count' => null,
            ];
        }

        foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
            if (!method_exists($reader, $method)) {
                throw new RuntimeException('Reader is missing method: ' . $method);
            }
        }

        $header = $reader->getHeader();
        $names = $reader->getNames();
        $imports = $reader->getImports();
        $exports = $reader->getExports();

        if (!is_array($header) || !is_array($names) || !is_array($imports) || !is_array($exports)) {
            throw new RuntimeException('Reader returned invalid package metadata.');
        }

        return [
            'status' => 'OK',
            'reader_engine' => $engine,
            'issues' => array_values(array_map('strval', $notes)),
            'validation_issues' => is_array($validationIssues) ? $validationIssues : [],
            'header' => $header,
            'name_count' => count($names),
            'import_count' => count($imports),
            'export_count' => count($exports),
        ];
    } catch (Throwable $error) {
        $message = get_class($error) . ': ' . $error->getMessage();
        $classified = \UnrealDb\Catalog\Application\Telemetry\CatalogInvalidUeErrorClassifier::classify($message);
        return [
            'status' => 'FAILED',
            'reader_engine' => $engine,
            'issues' => [$message],
            'validation_issues' => [[
                'code' => $classified['code'],
                'reason' => $classified['reason'],
                'arguments' => $classified['arguments'],
            ]],
            'header' => [],
            'name_count' => null,
            'import_count' => null,
            'export_count' => null,
        ];
    }
}

/** @return array<string,mixed> */
function inspect_unreal_file(string $inputPath, bool $full = false): array
{
    $resolvedPath = realpath($inputPath);
    if ($resolvedPath === false || !is_file($resolvedPath) || !is_readable($resolvedPath)) {
        return [
            'ok' => false,
            'input_path' => $inputPath,
            'error' => 'File not found or unreadable.',
        ];
    }

    $sourceName = basename($resolvedPath);
    $parsePath = $resolvedPath;
    $decoded = null;

    try {
        $wrapper = catalog_redirect_archive_extension($sourceName);
        if ($wrapper !== '') {
            $decoded = catalog_redirect_archive_decompress_to_temp($resolvedPath, $sourceName);
            $parsePath = (string)($decoded['path'] ?? '');
            if ($parsePath === '' || !is_file($parsePath)) {
                throw new RuntimeException('Redirect decoder did not produce a readable temporary package.');
            }
        }

        $header = @file_get_contents($parsePath, false, null, 0, 64);
        if (!is_string($header)) {
            throw new RuntimeException('Could not read decoded Unreal package header.');
        }

        $summary = gp_read_legacy_summary($parsePath);
        $byteProfile = inspect_unreal_byte_profile($parsePath);
        $corruption = \UnrealDb\Catalog\Infrastructure\Import\CatalogLegacyPackageCorruptionDetector::detectZeroToSpace($parsePath);
        $result = [
            'ok' => !empty($summary['ok']),
            'input_path' => $resolvedPath,
            'source_name' => $sourceName,
            'wrapper' => $wrapper !== '' ? strtoupper($wrapper) : 'NONE',
            'decoded_filename' => $wrapper !== ''
                ? (string)($decoded['filename'] ?? '')
                : $sourceName,
            'compressed_bytes' => $wrapper !== ''
                ? (int)($decoded['compressed_bytes'] ?? filesize($resolvedPath) ?: 0)
                : null,
            'decoded_bytes' => (int)(filesize($parsePath) ?: 0),
            'records' => $wrapper !== '' ? (int)($decoded['chunks'] ?? 0) : null,
            'decoder' => $wrapper !== '' ? (string)($decoded['decoder'] ?? '') : null,
            'package_magic' => catalog_redirect_archive_has_package_tag($header),
            'header_first_64_hex' => strtoupper(bin2hex($header)),
            'header_parse' => !empty($summary['ok']) ? 'OK' : 'FAILED',
            'package_version' => array_key_exists('version', $summary) ? (int)$summary['version'] : null,
            'licensee_version' => array_key_exists('licensee', $summary) ? (int)$summary['licensee'] : null,
            'engine_hint' => strtoupper(trim((string)($summary['engine_hint'] ?? 'UNKNOWN'))) ?: 'UNKNOWN',
            'package_tag_variant' => (string)($summary['package_tag_variant'] ?? ''),
            'reason' => (string)($summary['reason'] ?? ''),
            'error_code' => (string)($summary['error_code'] ?? ''),
            'error_arguments' => is_array($summary['error_arguments'] ?? null)
                ? $summary['error_arguments']
                : [],
            'zero_bytes' => $byteProfile['zero_bytes'],
            'space_bytes' => $byteProfile['space_bytes'],
            'first_4096_zero_bytes' => $byteProfile['first_4096_zero_bytes'],
            'first_4096_space_bytes' => $byteProfile['first_4096_space_bytes'],
            'whole_file_zero_to_space_pattern' => $corruption !== null,
            'corruption_code' => $corruption !== null ? 'unreal.zero_to_space_corruption' : '',
            'corruption_arguments' => $corruption ?? [],
        ];

        if ($full) {
            $fullValidation = inspect_unreal_full_validate($parsePath, $summary, $corruption);
            $result['full_validation'] = $fullValidation['status'];
            $result['reader_engine'] = $fullValidation['reader_engine'];
            $result['reader_issues'] = $fullValidation['issues'];
            $result['validation_issues'] = $fullValidation['validation_issues'];
            $result['reader_header'] = $fullValidation['header'];
            $result['name_count'] = $fullValidation['name_count'];
            $result['import_count'] = $fullValidation['import_count'];
            $result['export_count'] = $fullValidation['export_count'];

            $firstValidation = is_array($fullValidation['validation_issues'][0] ?? null)
                ? $fullValidation['validation_issues'][0]
                : [];
            $result['validation_code'] = trim((string)($firstValidation['code'] ?? ''));
            $result['validation_reason'] = trim((string)($firstValidation['reason'] ?? ''));
            $result['validation_arguments'] = is_array($firstValidation['arguments'] ?? null)
                ? $firstValidation['arguments']
                : [];
            // A source-defined metadata decoder is available for original
            // Unreal 1996 resource tables. Surface its real records in CLI
            // without claiming that unimplemented resource data is valid.
            if (($summary['format'] ?? '') === 'early_unreal_resource'
                && in_array((int)($summary['version'] ?? 0), [9, 10], true)) {
                try {
                    $result['original_resource_metadata'] =
                        \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceTableReader::read($parsePath);
                    $result['original_resource_metadata_status'] = 'DECODED';
                    try {
                        $headers = \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceHeaderReader::read($parsePath);
                        $result['original_resource_headers'] = $headers['resource_headers'];
                        $result['original_resource_headers_status'] = 'DECODED';
                        $result['original_resource_simple_database_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceSimpleDatabaseReader::read($parsePath, false);
                        $result['original_resource_actor_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceActorReader::read($parsePath, false);
                        $result['original_resource_class_property_tables'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceClassPropertyReader::read($parsePath, false);
                        $result['original_resource_model_level_headers'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceModelLevelReader::read($parsePath, false);
                        $result['original_resource_lightmesh_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceLightMeshReader::read($parsePath, false);
                        $result['original_resource_polys_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourcePolysReader::read($parsePath, false);
                        $result['original_resource_bsp_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspReader::read($parsePath, false);
                        $result['original_resource_lightmesh_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceLightMeshReader::read($parsePath, false);
                        $result['original_resource_polygon_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourcePolysReader::read($parsePath, false);
                        $result['original_resource_bsp_node_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspNodesReader::read($parsePath, false);
                        $result['original_resource_bsp_surface_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceBspSurfsReader::read($parsePath, false);
                        $result['original_resource_mesh_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceMeshReader::read($parsePath, false);
                        $result['original_resource_script_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceScriptReader::read($parsePath, false);
                        $result['original_resource_meshmap_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceMeshMapReader::read($parsePath, false);
                        $result['original_resource_geometry_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceGeometryReader::read($parsePath, false);
                        $result['original_resource_font_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceFontReader::read($parsePath, false);
                        $result['original_resource_sound_headers'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceSoundReader::read($parsePath, false);
                        $result['original_resource_texture_headers'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceTextureReader::read($parsePath, false);
                        $result['original_resource_database_headers'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceDatabaseReader::read($parsePath, false);
                        $result['original_resource_serialized_objects'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalSerializedObjectReader::read($parsePath, 'early_unreal_resource');
                        $result['original_resource_unconsumed_header_bytes'] = $headers['unconsumed_header_bytes'];
                    } catch (Throwable $headerError) {
                        $result['original_resource_headers_status'] = 'FAILED';
                        $result['original_resource_headers_error'] = $headerError->getMessage();
                    }
                } catch (Throwable $error) {
                    $result['original_resource_metadata_status'] = 'FAILED';
                    $result['original_resource_metadata_error'] = $error->getMessage();
                }
            }
            // Source-specific UE1 UObject::Serialize/FPropertyTag envelope.
            // This is a partial payload inspection, not class-native loading
            // or full property/engine validation. Limit CLI output size.
            if (($result['reader_engine'] ?? '') === 'UE1'
                && ($result['full_validation'] ?? '') === 'OK'
                && (int)($summary['version'] ?? 0) >= 34
                && (int)($summary['version'] ?? 0) <= 69) {
                try {
                    $objectReader = new \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader($parsePath);
                    $objectReader->validatePackage();
                    $exportCount = count($objectReader->getExports());
                    $samples = [];
                    $maxPreview = min($exportCount, 32);
                    for ($i = 0; $i < $maxPreview; $i++) {
                        try {
                            $tagged = \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader::inspect(
                                $parsePath, $objectReader, $i
                            );
                            $tagged['native_object'] = \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader::inspect(
                                $parsePath, $objectReader, $i, $tagged
                            );
                            $samples[] = $tagged;
                        } catch (Throwable $propertyError) {
                            $samples[] = [
                                'export_index' => $i,
                                'status' => 'unverified_class_specific_or_invalid_layout',
                                'detail' => $propertyError->getMessage(),
                            ];
                        }
                    }
                    $result['ue1_object_payload_preview'] = $samples;
                    $result['ue1_object_payload_preview_limit'] = 32;
                    $result['ue1_object_payload_preview_truncated'] = $exportCount > $maxPreview;
                    $result['ue1_object_payload_scope'] = 'source_tagged_properties_and_known_class_native_records; bulk_bytes_and_runtime_class_defaults_not_certified';
                } catch (Throwable $objectError) {
                    $result['ue1_object_payload_preview_error'] = $objectError->getMessage();
                }
            }
            if (($summary['format'] ?? '') === 'early_unrealfile'
                && in_array((int)($summary['version'] ?? 0), [22, 23], true)) {
                try {
                    $result['original_unrealfile_metadata'] =
                        \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileMetadataReader::read($parsePath);
                    $result['original_unrealfile_metadata_status'] = 'DECODED';
                    try {
                        $result['original_unrealfile_object_bases'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileObjectBaseReader::read($parsePath)['object_bases'];
                        $result['original_unrealfile_object_bases_status'] = 'DECODED';
                        $externalDiscovery = \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileExternalSchemaResolver::discoverSiblingProviders($parsePath);
                        $result['original_unrealfile_external_class_discovery'] = $externalDiscovery['diagnostics'];
                        $result['original_unrealfile_script_expressions'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileScriptExpressionReader::read($parsePath, false);
                        $result['original_unrealfile_properties_bin'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertiesReader::read($parsePath, false, $externalDiscovery['providers']);
                        $result['original_unrealfile_bit_archive'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBitArrayReader::read($parsePath, false);
                        $result['original_unrealfile_audio_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileAudioReader::read($parsePath, false);
                        $result['original_unrealfile_model_headers'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileModelHeaderReader::read($parsePath, false);
                        $result['original_unrealfile_mesh_headers'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileMeshHeaderReader::read($parsePath, false);
                        $result['original_unrealfile_level_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileLevelReader::read($parsePath, false);
                        $result['original_unrealfile_database_records'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileDatabaseDataReader::read($parsePath, false);
                        $result['original_unrealfile_binary_properties'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileBinaryPropertyReader::read($parsePath, false, $externalDiscovery['providers']);
                        $result['original_unrealfile_class_schemas'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileClassSchemaReader::read($parsePath, false);
                        $result['original_unrealfile_stack_prefixes'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileStackReader::read($parsePath, false);
                        $result['original_unrealfile_serialized_objects'] =
                            \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalSerializedObjectReader::read($parsePath, 'early_unrealfile');
                    } catch (Throwable $objectError) {
                        $result['original_unrealfile_object_bases_status'] = 'FAILED';
                        $result['original_unrealfile_object_bases_error'] = $objectError->getMessage();
                    }
                } catch (Throwable $error) {
                    $result['original_unrealfile_metadata_status'] = 'FAILED';
                    $result['original_unrealfile_metadata_error'] = $error->getMessage();
                }
            }
        }

        if (strlen($header) >= 16) {
            $bytes = array_values(unpack('C*', substr($header, 0, 16)) ?: []);
            if (count($bytes) === 16) {
                $versionHighIsSpace = $bytes[5] === 0x20;
                $licenseeHighIsSpace = $bytes[7] === 0x20;
                $flagsUpperAreSpaces = $bytes[9] === 0x20 && $bytes[10] === 0x20 && $bytes[11] === 0x20;
                $nameCountUpperAreSpaces = $bytes[13] === 0x20 && $bytes[14] === 0x20 && $bytes[15] === 0x20;

                if ($versionHighIsSpace && $licenseeHighIsSpace && ($flagsUpperAreSpaces || $nameCountUpperAreSpaces)) {
                    $candidateVersion = $bytes[4];
                    $candidateLicensee = $bytes[6];
                    $result['possible_zero_to_space_header_corruption'] = true;
                    $result['candidate_package_version_if_20_high_bytes_are_00'] = $candidateVersion;
                    $result['candidate_licensee_version_if_20_high_bytes_are_00'] = $candidateLicensee;
                } else {
                    $result['possible_zero_to_space_header_corruption'] = false;
                }
            }
        }

        return $result;
    } catch (Throwable $error) {
        return [
            'ok' => false,
            'input_path' => $resolvedPath,
            'source_name' => $sourceName,
            'error' => get_class($error) . ': ' . $error->getMessage(),
        ];
    } finally {
        if (is_array($decoded)) {
            $temporary = (string)($decoded['path'] ?? '');
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}

function inspect_unreal_print_human(array $result): void
{
    echo PHP_EOL . str_repeat('=', 68) . PHP_EOL;
    echo (string)($result['source_name'] ?? basename((string)($result['input_path'] ?? ''))) . PHP_EOL;
    echo 'Path                         : ' . (string)($result['input_path'] ?? '') . PHP_EOL;

    if (isset($result['error'])) {
        echo 'ERROR                        : ' . (string)$result['error'] . PHP_EOL;
        return;
    }

    echo 'Wrapper                      : ' . (string)($result['wrapper'] ?? '') . PHP_EOL;
    if (($result['wrapper'] ?? 'NONE') !== 'NONE') {
        echo 'Redirect decode              : OK' . PHP_EOL;
        echo 'Decoded filename             : ' . (string)($result['decoded_filename'] ?? '') . PHP_EOL;
        echo 'Compressed bytes             : ' . (string)($result['compressed_bytes'] ?? 0) . PHP_EOL;
        echo 'Decoded bytes                : ' . (string)($result['decoded_bytes'] ?? 0) . PHP_EOL;
        echo 'Records                      : ' . (string)($result['records'] ?? 0) . PHP_EOL;
        echo 'Decoder                      : ' . (string)($result['decoder'] ?? '') . PHP_EOL;
    } else {
        echo 'Package bytes                : ' . (string)($result['decoded_bytes'] ?? 0) . PHP_EOL;
    }

    echo 'Package magic                : ' . (!empty($result['package_magic']) ? 'YES' : 'NO') . PHP_EOL;
    echo 'Header first 64              : ' . (string)($result['header_first_64_hex'] ?? '') . PHP_EOL;
    echo 'Header parse                 : ' . (string)($result['header_parse'] ?? '') . PHP_EOL;
    echo 'Package version              : ' . var_export($result['package_version'] ?? null, true) . PHP_EOL;
    echo 'Licensee version             : ' . var_export($result['licensee_version'] ?? null, true) . PHP_EOL;
    echo 'Engine hint                  : ' . (string)($result['engine_hint'] ?? 'UNKNOWN') . PHP_EOL;
    if (!empty($result['package_tag_variant'])) {
        echo 'Package tag variant          : ' . (string)$result['package_tag_variant'] . PHP_EOL;
    }
    if (array_key_exists('full_validation', $result)) {
        echo 'Full reader validation       : ' . (string)$result['full_validation'] . PHP_EOL;
        echo 'Reader engine                : ' . (string)($result['reader_engine'] ?? '') . PHP_EOL;
        if (($result['full_validation'] ?? '') === 'OK') {
            echo 'Names                        : ' . (string)($result['name_count'] ?? 0) . PHP_EOL;
            echo 'Imports                      : ' . (string)($result['import_count'] ?? 0) . PHP_EOL;
            echo 'Exports                      : ' . (string)($result['export_count'] ?? 0) . PHP_EOL;
        } else {
            if (!empty($result['validation_code'])) {
                echo 'Validation code              : ' . (string)$result['validation_code'] . PHP_EOL;
            }
            if (!empty($result['validation_reason'])) {
                echo 'Validation reason            : ' . (string)$result['validation_reason'] . PHP_EOL;
            }
            if (!empty($result['validation_arguments'])) {
                echo 'Validation values            : '
                    . json_encode($result['validation_arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . PHP_EOL;
            }
            foreach ((array)($result['reader_issues'] ?? []) as $issue) {
                echo 'Reader issue                 : ' . trim((string)$issue) . PHP_EOL;
            }
        }
    }
    echo 'Zero bytes (whole file)      : ' . (string)($result['zero_bytes'] ?? 0) . PHP_EOL;
    echo 'Space bytes (whole file)     : ' . (string)($result['space_bytes'] ?? 0) . PHP_EOL;
    echo 'Zero bytes (first 4096)      : ' . (string)($result['first_4096_zero_bytes'] ?? 0) . PHP_EOL;
    echo 'Space bytes (first 4096)     : ' . (string)($result['first_4096_space_bytes'] ?? 0) . PHP_EOL;
    echo 'Whole-file 00->20 pattern    : ' . (!empty($result['whole_file_zero_to_space_pattern']) ? 'YES' : 'NO') . PHP_EOL;
    if (!empty($result['corruption_code'])) {
        echo 'Corruption code              : ' . (string)$result['corruption_code'] . PHP_EOL;
    }

    if (!empty($result['reason'])) {
        echo 'Reason                       : ' . (string)$result['reason'] . PHP_EOL;
    }
    if (!empty($result['error_code'])) {
        echo 'Error code                   : ' . (string)$result['error_code'] . PHP_EOL;
    }
    if (!empty($result['error_arguments'])) {
        echo 'Arguments                    : '
            . json_encode($result['error_arguments'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . PHP_EOL;
    }

    if (!empty($result['possible_zero_to_space_header_corruption'])) {
        echo 'Suspicious 00->20 header     : YES' . PHP_EOL;
        echo 'Candidate package version    : '
            . (string)($result['candidate_package_version_if_20_high_bytes_are_00'] ?? '')
            . PHP_EOL;
        echo 'Candidate licensee version   : '
            . (string)($result['candidate_licensee_version_if_20_high_bytes_are_00'] ?? '')
            . PHP_EOL;
        echo 'Diagnostic note              : candidate only; package bytes were not modified.' . PHP_EOL;
    } else {
        echo 'Suspicious 00->20 header     : NO' . PHP_EOL;
    }
}

$arguments = array_slice($argv, 1);
$json = false;
$full = false;
$recursive = false;
$paths = [];
foreach ($arguments as $argument) {
    if ($argument === '--json') {
        $json = true;
        continue;
    }
    if ($argument === '--full') {
        $full = true;
        continue;
    }
    if ($argument === '--recursive') {
        $recursive = true;
        continue;
    }
    $paths[] = $argument;
}

if ($paths === []) {
    fwrite(
        STDERR,
        "Usage: php catalog/bin/inspect-unreal-file.php [--json] [--full] [--recursive] <file-or-directory> [...]\n"
    );
    exit(2);
}

$expandedPaths = [];
foreach ($paths as $path) {
    if (is_dir($path)) {
        if (!$recursive) {
            fwrite(STDERR, 'Directory requires --recursive: ' . $path . PHP_EOL);
            exit(2);
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $candidate = $item->getPathname();
            if (preg_match('/\\.(?:uz|uz2|uz3)$/i', $candidate) === 1) {
                $expandedPaths[] = $candidate;
            }
        }
        continue;
    }
    $expandedPaths[] = $path;
}
$expandedPaths = array_values(array_unique($expandedPaths));
natcasesort($expandedPaths);
$expandedPaths = array_values($expandedPaths);

$results = [];
$failed = false;
foreach ($expandedPaths as $path) {
    $result = inspect_unreal_file($path, $full);
    $results[] = $result;
    if (isset($result['error']) || ($full && ($result['full_validation'] ?? '') !== 'OK')) {
        $failed = true;
    }
}

if ($json) {
    echo json_encode(
        count($results) === 1 ? $results[0] : $results,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) . PHP_EOL;
} else {
    foreach ($results as $result) {
        inspect_unreal_print_human($result);
    }

    if ($recursive || count($results) > 1) {
        $okCount = 0;
        $failedCount = 0;
        $codes = [];
        foreach ($results as $result) {
            $isOk = !isset($result['error'])
                && (!$full || ($result['full_validation'] ?? '') === 'OK');
            if ($isOk) {
                $okCount++;
                continue;
            }
            $failedCount++;
            $code = trim((string)($result['validation_code'] ?? $result['corruption_code'] ?? $result['error_code'] ?? ''));
            if ($code === '') {
                $code = isset($result['error']) ? 'runtime_error' : 'unclassified_failure';
            }
            $codes[$code] = ($codes[$code] ?? 0) + 1;
        }
        arsort($codes);

        echo PHP_EOL . str_repeat('=', 68) . PHP_EOL;
        echo 'SUMMARY' . PHP_EOL;
        echo 'Files tested                 : ' . count($results) . PHP_EOL;
        echo 'Passed                       : ' . $okCount . PHP_EOL;
        echo 'Failed                       : ' . $failedCount . PHP_EOL;
        foreach ($codes as $code => $count) {
            echo 'Failure ' . $code . ' : ' . $count . PHP_EOL;
        }
    }
}

exit($failed ? 1 : 0);
