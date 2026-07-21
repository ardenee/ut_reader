<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use PDOException;
use Throwable;
use UnrealDb\Catalog\Application\Jobs\JobCancellationRequested;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

final class CatalogPakImportJobHandler implements JobHandler
{
    private const MAX_RESULT_MESSAGES = 200;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::IMPORT_STAGED_PAK;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $pakId = 0;
        $extracted = null;
        $archiveStore = new CatalogPakArchiveStore($this->config);

        try {
            $payload = $job->payload;
            $gameId = $this->positiveInt($payload, 'game_id');
            $stagedPath = $this->requiredString($payload, 'staged_path');
            $originalName = $this->requiredString($payload, 'original_name');
            $strict = !array_key_exists('strict_profile', $payload) || (bool)$payload['strict_profile'];
            $userId = isset($payload['user_id']) && (int)$payload['user_id'] > 0 ? (int)$payload['user_id'] : null;

            $incoming = new CatalogIncomingFileStore($this->config);
            $sourcePath = $incoming->resolve($stagedPath);
            $this->verifyIdentity($sourcePath, $payload);

            require_once __DIR__ . '/../../../lib/CatalogSupport.php';
            require_once __DIR__ . '/../../../lib/CatalogScanner.php';
            require_once __DIR__ . '/../../../lib/CatalogPakArchive.php';
            require_once __DIR__ . '/../../../lib/GameProfiles.php';

            if (!\catalog_pak_archive_is_supported_filename($originalName)) {
                throw new \RuntimeException('Staged file is not a supported PAK archive.');
            }
            if (!CatalogPakArchiveStore::schemaInstalled($this->db)) {
                throw new \RuntimeException('PAK archive tables are missing. Run the database migrations first.');
            }

            $game = \catalog_one(
                $this->db,
                'SELECT g.id,g.name,g.slug,p.engine_key profile_engine FROM ue_games g '
                . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id WHERE g.id=?',
                [$gameId]
            );
            if (!$game) {
                throw new \RuntimeException('Target game no longer exists: ' . $gameId);
            }
            $engineMajor = $this->engineMajor((string)($game['profile_engine'] ?? ''));
            if (!in_array($engineMajor, [4, 5], true)) {
                throw new \RuntimeException('Original PAK archive management is available only for UE4 or UE5 game profiles.');
            }

            $context->checkpoint([
                'stage' => 'pak_extract',
                'done' => 0,
                'total' => 1,
                'percent' => 1,
                'message' => 'Extracting and validating UE' . $engineMajor . ' PAK: ' . basename($originalName),
            ]);

            $footers = \catalog_pak_footer_candidates($sourcePath);
            if ($footers === []) {
                throw new \RuntimeException('Unsupported PAK file: no Unreal PAK footer was found.');
            }

            /*
             * Extraction may reject an early footer/layout candidate and succeed
             * with a later one. The retained archive metadata and entry list must
             * therefore be selected from the same index that produced the files.
             */
            $extracted = \catalog_pak_archive_extract_to_temp($this->config, $sourcePath, $originalName);
            $extractedFiles = is_array($extracted['files'] ?? null) ? $extracted['files'] : [];
            $extractedByPath = [];
            foreach ($extractedFiles as $file) {
                $relative = $this->normalizeEntryPath((string)($file['relative'] ?? ''));
                if ($relative !== '') {
                    $extractedByPath[strtolower($relative)] = $file;
                }
            }

            [$footer, $index] = $this->selectIndexForExtractedFiles(
                $sourcePath,
                $footers,
                $extractedByPath,
                (string)($extracted['log'] ?? '')
            );

            $pakId = $archiveStore->createOrReset(
                $this->db,
                $game,
                $sourcePath,
                $originalName,
                $footer,
                $index,
                $userId
            );

            $profile = \gp_required_profile_for_game($this->db, $gameId);
            $allowed = \scanner_profile_extensions($profile, $this->config);
            $entries = is_array($index['entries'] ?? null) ? $index['entries'] : [];
            $total = count($entries);
            $imported = 0;
            $duplicates = 0;
            $aliases = 0;
            $failed = 0;
            $skipped = 0;
            $notExtracted = 0;
            $messages = [];

            foreach ($entries as $entryIndex => $entry) {
                $display = $this->normalizeEntryPath((string)($entry['filename'] ?? ''));
                $extractedFile = $display !== '' ? ($extractedByPath[strtolower($display)] ?? null) : null;
                $entryId = $archiveStore->addEntry(
                    $this->db,
                    $pakId,
                    (int)$entryIndex,
                    is_array($entry) ? $entry : [],
                    is_array($extractedFile)
                );

                $context->checkpoint([
                    'stage' => 'pak_import',
                    'done' => $entryIndex,
                    'total' => max(1, $total),
                    'percent' => 5 + (int)floor(($entryIndex * 83) / max(1, $total)),
                    'message' => 'Cataloging PAK entry ' . ($entryIndex + 1) . '/' . max(1, $total) . ': ' . ($display !== '' ? $display : 'unnamed entry'),
                    'pak_id' => $pakId,
                    'imported' => $imported,
                    'duplicates' => $duplicates,
                    'aliases' => $aliases,
                    'failed' => $failed,
                    'skipped' => $skipped,
                    'not_extracted' => $notExtracted,
                ]);

                if (!is_array($extractedFile)) {
                    $notExtracted++;
                    $archiveStore->updateEntry(
                        $this->db,
                        $entryId,
                        !empty($entry['encrypted']) ? 'encrypted' : 'not_extracted',
                        null,
                        !empty($entry['encrypted'])
                            ? 'Entry is encrypted.'
                            : 'Entry uses an unsupported compression method or could not be extracted.'
                    );
                    continue;
                }

                $name = \catalog_clean_unreal_filename(basename($display));
                $extension = \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
                if (
                    $extension === ''
                    || in_array($extension, ['uexp', 'ubulk', 'uptnl', 'm_ubulk'], true)
                    || !in_array($extension, $allowed, true)
                ) {
                    $skipped++;
                    $archiveStore->updateEntry(
                        $this->db,
                        $entryId,
                        'skipped',
                        null,
                        'Extracted entry is not a standalone package accepted by the selected game profile.'
                    );
                    continue;
                }

                $path = (string)($extractedFile['path'] ?? '');
                try {
                    $result = \scanner_scan_uploaded_file(
                        $this->db,
                        $this->config,
                        $gameId,
                        $path,
                        $name,
                        $userId,
                        $strict,
                        static function (array $progress) use ($context): void {
                            $context->heartbeatIfDue($progress);
                        },
                        false,
                        [
                            'source_relative_path' => $display,
                            'source_pak_id' => $pakId,
                            'source_pak_entry_id' => $entryId,
                            'defer_dependency_rebuild' => true,
                        ]
                    );
                    $status = (string)($result[0] ?? 'verified');
                    $fileId = (int)($result[1] ?? 0);
                    if ($status === 'duplicate') {
                        $duplicates++;
                    } elseif ($status === 'alias') {
                        $aliases++;
                    } else {
                        $imported++;
                    }
                    $archiveStore->updateEntry(
                        $this->db,
                        $entryId,
                        $status,
                        $fileId > 0 ? $fileId : null,
                        (string)($result[2] ?? '')
                    );
                    if (count($messages) < self::MAX_RESULT_MESSAGES) {
                        $messages[] = [
                            'status' => $status,
                            'file' => $display,
                            'message' => (string)($result[2] ?? ''),
                            'file_id' => $fileId,
                            'pak_entry_id' => $entryId,
                        ];
                    }
                } catch (JobCancellationRequested $error) {
                    throw $error;
                } catch (Throwable $error) {
                    $failed++;
                    $stager = new LegacyUnverifiedFileStager($this->db, $this->config);
                    $staged = $stager->stageFailedUpload(
                        $gameId,
                        $path,
                        $name,
                        'PAK entry ' . $display . ': ' . $error->getMessage(),
                        $userId,
                        $display
                    );
                    $fileId = (int)($staged['file_id'] ?? 0);
                    $status = $staged !== null ? 'unverified' : 'rejected';
                    $archiveStore->updateEntry($this->db, $entryId, $status, $fileId > 0 ? $fileId : null, $this->shortError($error));
                    if (count($messages) < self::MAX_RESULT_MESSAGES) {
                        $messages[] = [
                            'status' => $status,
                            'file' => $display,
                            'message' => $this->shortError($error),
                            'file_id' => $fileId,
                            'pak_entry_id' => $entryId,
                        ];
                    }
                }
            }

            if (($imported + $aliases) > 0) {
                $context->checkpoint([
                    'stage' => 'dependency_refresh',
                    'done' => max(1, $total),
                    'total' => max(1, $total),
                    'percent' => 90,
                    'message' => 'Refreshing game dependency links once after the PAK import.',
                    'pak_id' => $pakId,
                ]);
                \scanner_rebuild_game(
                    $this->db,
                    $this->config,
                    $gameId,
                    static function (array $progress) use ($context): void {
                        $context->heartbeatIfDue($progress);
                    },
                    90,
                    98
                );
            }

            $archiveStore->finish(
                $this->db,
                $pakId,
                count($extractedFiles),
                $skipped + $notExtracted,
                (string)($extracted['log'] ?? '')
            );

            $context->checkpoint([
                'stage' => 'complete',
                'done' => max(1, $total),
                'total' => max(1, $total),
                'percent' => 100,
                'message' => 'Original UE' . $engineMajor . ' PAK retained; archive entries and extracted packages were cataloged.',
                'pak_id' => $pakId,
                'imported' => $imported,
                'duplicates' => $duplicates,
                'aliases' => $aliases,
                'failed' => $failed,
                'skipped' => $skipped,
                'not_extracted' => $notExtracted,
            ]);

            return [
                'operation' => 'import_staged_pak',
                'status' => 'completed',
                'pak_id' => $pakId,
                'game_id' => $gameId,
                'game_name' => (string)$game['name'],
                'engine_major' => $engineMajor,
                'source_name' => $originalName,
                'entry_count' => $total,
                'extracted_files' => count($extractedFiles),
                'imported' => $imported,
                'duplicates' => $duplicates,
                'aliases' => $aliases,
                'failed' => $failed,
                'skipped' => $skipped,
                'not_extracted' => $notExtracted,
                'messages' => $messages,
                'messages_truncated' => ($imported + $duplicates + $aliases + $failed) > count($messages),
                'extract_log' => substr((string)($extracted['log'] ?? ''), 0, 20000),
            ];
        } catch (JobCancellationRequested $error) {
            $archiveStore->markFailed($this->db, $pakId, 'Import cancelled.');
            throw $error;
        } catch (PDOException|\InvalidArgumentException|\Error $error) {
            $archiveStore->markFailed($this->db, $pakId, $error->getMessage());
            throw $error;
        } catch (Throwable $error) {
            $archiveStore->markFailed($this->db, $pakId, $error->getMessage());
            if ($this->isInfrastructureFailure($error)) {
                throw $error;
            }
            $message = $this->shortError($error);
            $context->checkpoint([
                'stage' => 'failed',
                'done' => 100,
                'total' => 100,
                'percent' => 100,
                'status' => 'failed',
                'pak_id' => $pakId,
                'message' => $message,
            ]);
            return [
                'operation' => 'import_staged_pak',
                'status' => 'failed',
                'pak_id' => $pakId,
                'message' => $message,
                'original_name' => (string)($job->payload['original_name'] ?? 'archive.pak'),
            ];
        } finally {
            if (is_array($extracted) && isset($extracted['dir'])) {
                require_once __DIR__ . '/../../../lib/CatalogPakArchive.php';
                \catalog_pak_archive_delete_tree((string)$extracted['dir']);
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $footers
     * @param array<string,array<string,mixed>> $extractedByPath
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function selectIndexForExtractedFiles(
        string $sourcePath,
        array $footers,
        array $extractedByPath,
        string $extractLog
    ): array {
        $expectedVersion = null;
        $expectedLayout = '';
        $expectedMagicOffset = null;
        if (preg_match('/version=([0-9]+); layout=([^;]+); magic_offset=(-?[0-9]+)/', $extractLog, $match) === 1) {
            $expectedVersion = (int)$match[1];
            $expectedLayout = trim((string)$match[2]);
            $expectedMagicOffset = (int)$match[3];
        }

        $bestFooter = null;
        $bestIndex = null;
        $bestMatches = -1;
        $lastError = '';

        foreach ($footers as $candidate) {
            try {
                $candidateIndex = \catalog_pak_parse_index($sourcePath, $candidate);
            } catch (Throwable $error) {
                $lastError = $error->getMessage();
                continue;
            }

            $matches = 0;
            $entries = is_array($candidateIndex['entries'] ?? null) ? $candidateIndex['entries'] : [];
            foreach ($entries as $entry) {
                $path = $this->normalizeEntryPath((string)($entry['filename'] ?? ''));
                if ($path !== '' && isset($extractedByPath[strtolower($path)])) {
                    $matches++;
                }
            }

            $metadataMatches = $expectedVersion !== null
                && (int)($candidate['version'] ?? -1) === $expectedVersion
                && (string)($candidate['layout'] ?? '') === $expectedLayout
                && (int)($candidate['magic_offset'] ?? -2) === $expectedMagicOffset;
            if ($metadataMatches) {
                return [$candidate, $candidateIndex];
            }

            if ($matches > $bestMatches) {
                $bestMatches = $matches;
                $bestFooter = $candidate;
                $bestIndex = $candidateIndex;
            }
        }

        if (is_array($bestFooter) && is_array($bestIndex) && $bestMatches > 0) {
            return [$bestFooter, $bestIndex];
        }

        throw new \RuntimeException(
            'Could not match the successfully extracted PAK files to a parsed index.'
            . ($lastError !== '' ? ' Last index error: ' . $lastError : '')
        );
    }

    /** @param array<string,mixed> $payload */
    private function verifyIdentity(string $path, array $payload): void
    {
        $expected = strtolower(trim((string)($payload['sha256'] ?? '')));
        if ($expected === '') {
            return;
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new \RuntimeException('Staged import file identity changed before execution.');
        }
    }

    private function normalizeEntryPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function engineMajor(string $engineKey): int
    {
        return preg_match('/UE\s*([0-9]+)/i', $engineKey, $match) === 1 ? (int)$match[1] : 0;
    }

    /** @param array<string,mixed> $payload */
    private function positiveInt(array $payload, string $field): int
    {
        $value = (int)($payload[$field] ?? 0);
        if ($value < 1) {
            throw new \InvalidArgumentException('PAK import payload requires positive ' . $field . '.');
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    private function requiredString(array $payload, string $field): string
    {
        $value = trim((string)($payload[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException('PAK import payload requires ' . $field . '.');
        }
        return $value;
    }

    private function isInfrastructureFailure(Throwable $error): bool
    {
        $message = strtolower(trim($error->getMessage()));
        foreach ([
            'pak archive tables are missing',
            'staged import file is unavailable',
            'staged import file identity changed',
            'target game no longer exists',
            'could not copy the original pak',
            'original pak copy verification failed',
            'could not publish the original pak',
            'sqlstate[',
            'job lease no longer belongs',
        ] as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        $message = preg_replace('/^RuntimeException:\s*/', '', $message) ?? $message;
        $message = preg_split('/\s+File:\s+|\s+Trace:\s+/', $message)[0] ?? $message;
        return trim($message) !== '' ? trim($message) : 'PAK import failed.';
    }
}
