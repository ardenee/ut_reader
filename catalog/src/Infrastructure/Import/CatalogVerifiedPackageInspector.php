<?php
/**
 * Reads and classifies one candidate verified Unreal package without persisting it.
 *
 * Parser selection, filesystem hashing and generation-specific reader behaviour
 * are Infrastructure concerns. The result is returned as an immutable
 * Application DTO so later orchestration does not depend on reader internals.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Import\CatalogVerifiedPackageInspection;
use UnrealDb\Catalog\Application\Import\Contract\VerifiedPackageInspectorPort;

final class CatalogVerifiedPackageInspector implements VerifiedPackageInspectorPort
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
    }

    public function inspect(
        int $gameId,
        string $temporaryPath,
        string $submittedOriginalName,
        bool $strictProfile,
        string $sourceRelativePath,
        ?callable $progress = null
    ): CatalogVerifiedPackageInspection {
        $sourceRelativePath = \scanner_normalize_source_relative_path($sourceRelativePath);
        $originalName = $submittedOriginalName;
        $sourceOriginalName = \scanner_original_name_from_source_relative($sourceRelativePath);
        if ($sourceOriginalName !== '') {
            $originalName = $sourceOriginalName;
        }
        $originalName = \scanner_clean_original_filename($originalName);
        \scanner_emit_percent($progress, 'start', 0, 'Preparing ' . $originalName);

        $game = \catalog_one($this->db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new RuntimeException('Game not found');
        }
        $profile = \gp_required_profile_for_game($this->db, $gameId);
        $profileEngine = strtoupper((string)$profile['engine_key']);
        $extension = \catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION));

        $size = filesize($temporaryPath) ?: 0;
        if ($size <= 0 || $size > (int)$this->config['max_upload_bytes']) {
            throw new RuntimeException('Bad file size: ' . \catalog_bytes((int)$size));
        }

        \scanner_emit_percent($progress, 'scan', 2, 'Reading package header');
        $classification = \gp_classify_file($this->db, $gameId, $temporaryPath, $originalName);

        if (empty($classification['header_ok'])) {
            $headerCode = trim((string)($classification['header_error_code'] ?? 'unreal.invalid_package'));
            $headerArguments = is_array($classification['header_error_arguments'] ?? null)
                ? $classification['header_error_arguments']
                : [];
            $headerReason = match ($headerCode) {
                'unreal.magic_not_found' => 'Magic not found',
                'unreal.header_too_short' => 'Package header too short',
                'unreal.header_read_failed' => 'Could not read package header',
                default => trim((string)(($classification['notes'][0] ?? 'Invalid Unreal package'))),
            };
            throw new CatalogInvalidPackageException($headerReason, $headerCode, $headerArguments);
        }

        if ($strictProfile && empty($classification['ok_for_selected_game'])) {
            $suggested = [];
            foreach ($classification['suggested_games'] as $suggestion) {
                $suggested[] = $suggestion['game_name'] . ' (' . $suggestion['engine_key'] . ')';
            }
            throw new CatalogProfileMismatchException(
                'Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown')
                . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '.'
                . ($suggested ? ' Suggested: ' . implode(', ', $suggested) : ''),
                [
                    'detected_engine' => (string)($classification['detected_engine'] ?? 'UNKNOWN'),
                    'selected_engine' => (string)($classification['selected_engine'] ?? 'UNKNOWN'),
                    'package_version' => $classification['package_version'] ?? null,
                    'licensee_version' => $classification['licensee_version'] ?? null,
                    'suggested_games' => $classification['suggested_games'] ?? [],
                ]
            );
        }

        $readerEngine = strtoupper(trim((string)($classification['reader_engine'] ?? 'UNKNOWN')));
        $detectedEngine = strtoupper(trim((string)($classification['detected_engine'] ?? 'UNKNOWN')));
        if (!in_array($readerEngine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
            throw new CatalogInvalidPackageException(
                'No supported package reader can be selected from serialized header data. '
                . implode(' ', (array)($classification['notes'] ?? []))
            );
        }

        $sourcePackageName = '';
        if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
            $sourcePackageName = \scanner_ue_package_name_from_source_relative($sourceRelativePath);
            if ($sourcePackageName === '') {
                throw new RuntimeException(
                    'UE4 package identity requires a mounted source-relative path, matching UT4 '
                    . 'FPackageName::FilenameToLongPackageName behaviour. Reimport using Local Source Scan, folder '
                    . 'upload, PAK import, or a source manifest path; single loose UE4 files cannot be catalogued safely.'
                );
            }
            $packageName = $sourcePackageName;
        } else {
            $packageName = \scanner_logical_package_name($originalName);
        }

        \scanner_emit_percent($progress, 'scan', 4, 'Hashing file');
        $md5 = md5_file($temporaryPath);
        $sha1 = sha1_file($temporaryPath);
        if (!$md5 || !$sha1) {
            throw new RuntimeException('Could not hash file');
        }

        \scanner_emit_percent($progress, 'scan', 7, 'Opening ' . $readerEngine . ' reader');
        $readerClass = \scanner_load_reader_class($this->config, $readerEngine);
        $ue4ReaderOptions = [];
        if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
            $ue4ReaderOptions = \catalog_ue4_reader_options($this->config, $game, $profile);
            \catalog_ue4_set_next_reader_options($ue4ReaderOptions);
        }
        $package = new $readerClass($temporaryPath);

        \scanner_emit_percent($progress, 'scan', 9, 'Validating package');
        $issues = method_exists($package, 'validatePackage')
            ? $package->validatePackage()
            : (method_exists($package, 'getDebugErrors') ? $package->getDebugErrors() : []);
        $validationIssues = method_exists($package, 'getValidationIssues')
            ? $package->getValidationIssues()
            : [];
        [$fatalIssues, $scanNotes] = \scanner_split_reader_issues($issues);
        if ($fatalIssues) {
            $structured = is_array($validationIssues) && is_array($validationIssues[0] ?? null)
                ? $validationIssues[0]
                : [];
            $reason = trim((string)($structured['reason'] ?? ''));
            if ($reason === '') {
                $reason = implode("\n", $fatalIssues);
            }
            throw new CatalogInvalidPackageException(
                $reason,
                trim((string)($structured['code'] ?? 'unreal.invalid_package')),
                is_array($structured['arguments'] ?? null) ? $structured['arguments'] : []
            );
        }
        foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
            if (!method_exists($package, $method)) {
                throw new RuntimeException('Reader is missing method: ' . $method);
            }
        }

        \scanner_emit_percent($progress, 'scan', 11, 'Reading header');
        $header = $package->getHeader();
        $packageGuid = (string)($header['guid'] ?? '');
        $names = $package->getNames();
        $packageName = \scanner_package_name_from_reader($packageName, $readerEngine, $names, $header);

        \scanner_emit_percent($progress, 'scan', 14, 'Reading names table');
        \scanner_emit_percent($progress, 'scan', 17, 'Reading imports table');
        $imports = $package->getImports();
        \scanner_emit_percent($progress, 'scan', 20, 'Reading exports table');
        $exports = $package->getExports();
        \scanner_emit_percent(
            $progress,
            'scan',
            22,
            'Read ' . count($names) . ' names, ' . count($imports) . ' imports, ' . count($exports) . ' exports'
        );

        $scanNotesText = $this->scanNotes(
            $classification,
            $profileEngine,
            $readerEngine,
            $packageName,
            $header,
            $ue4ReaderOptions,
            $scanNotes,
            $submittedOriginalName,
            $originalName,
            $sourceRelativePath,
            $sourcePackageName,
            $strictProfile,
            $detectedEngine,
            $game,
            $profile
        );

        return new CatalogVerifiedPackageInspection(
            $game,
            $profile,
            $classification,
            $header,
            $names,
            $imports,
            $exports,
            $ue4ReaderOptions,
            $submittedOriginalName,
            $originalName,
            $sourceRelativePath,
            $profileEngine,
            $readerEngine,
            $detectedEngine,
            $sourcePackageName,
            $packageName,
            $extension,
            (int)$size,
            $md5,
            $sha1,
            $packageGuid,
            $scanNotesText
        );
    }

    /**
     * @param array<string,mixed> $classification
     * @param array<string,mixed> $header
     * @param list<string> $scanNotes
     * @param array<string,mixed> $ue4ReaderOptions
     * @param array<string,mixed> $game
     * @param array<string,mixed> $profile
     */
    private function scanNotes(
        array $classification,
        string $profileEngine,
        string $readerEngine,
        string $packageName,
        array $header,
        array $ue4ReaderOptions,
        array $scanNotes,
        string $submittedOriginalName,
        string $originalName,
        string $sourceRelativePath,
        string $sourcePackageName,
        bool $strictProfile,
        string $detectedEngine,
        array $game,
        array $profile
    ): ?string {
        $cleanNote = $submittedOriginalName !== $originalName
            ? '; cleaned filename=' . $originalName . ' from upload=' . basename($submittedOriginalName)
            : '';
        $sourceNote = $sourceRelativePath !== '' ? '; source-relative=' . $sourceRelativePath : '';
        $sourcePackageNote = $sourcePackageName !== '' ? '; source package=' . $sourcePackageName : '';
        $parserNote = '';
        if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
            $parserProfile = is_array($header['parserProfile'] ?? null) && $header['parserProfile']
                ? $header['parserProfile']
                : ($ue4ReaderOptions['parser_profile']
                    ?? \catalog_ue4_parser_profile($this->config, $game, $profile));
            $parserKey = (string)($header['parserProfileKey'] ?? ($parserProfile['profile_key'] ?? 'standard-ue4'));
            $parserLabel = (string)(
                $header['parserProfileLabel']
                ?? ($parserProfile['label'] ?? 'Standard UE4 package parser')
            );
            $assumedParserVersion = (int)(
                $header['assumedUnversionedParserVersion']
                ?? ($parserProfile['assumed_unversioned_parser_version'] ?? 0)
            );
            $parserNote = '; UE4 parser profile=' . $parserKey . ' (' . $parserLabel . ')'
                . ($assumedParserVersion > 0
                    ? '; assumed UE4 parser version=' . $assumedParserVersion
                    : '');
        }

        $notes = array_merge($scanNotes, [
            'Profile engine=' . $profileEngine
            . '; header-selected package reader=' . $readerEngine
            . $parserNote
            . '; package=' . $packageName
            . '; compatibility=' . ($classification['compatibility_status'] ?? 'native')
            . '; detection=' . $classification['confidence']
            . $cleanNote . $sourceNote . $sourcePackageNote
            . '; ' . implode(' ', $classification['notes'])
        ]);

        if (!$strictProfile && ($detectedEngine !== '' && $detectedEngine !== $profileEngine)) {
            $notes[] = 'Administrator compatibility override: catalogued under ' . $profileEngine
                . ' game using header-detected ' . $detectedEngine . ' reader.';
        }

        return $notes ? implode("\n", $notes) : null;
    }
}
