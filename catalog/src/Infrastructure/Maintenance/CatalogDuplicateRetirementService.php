<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns GUID duplicate retirement transactions and projection reconciliation.
 * Why: Duplicate-management pages should render/select records, not mutate file identities and source locations directly.
 * Role: Infrastructure maintenance service shared by explicit duplicate selection and bulk Keep actions.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue;

final class CatalogDuplicateRetirementService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
    }

    /**
     * Retire explicit duplicate IDs under the posted canonical IDs.
     *
     * @param list<array{canonical_id:int,duplicate_ids:list<int>}> $groups
     * @return array{retired:int,groups:int}
     */
    public function retireSelectedGroups(array $groups, int $maxRetire): array
    {
        if ($groups === []) {
            throw new RuntimeException('Choose at least one canonical file and at least one duplicate file to retire.');
        }

        $total = array_sum(array_map(
            static fn(array $group): int => count($group['duplicate_ids']),
            $groups
        ));
        if ($total > $maxRetire) {
            throw new RuntimeException('Too many duplicate files selected. Process at most ' . $maxRetire . ' rows at once.');
        }

        $reconciliation = [];
        $this->db->beginTransaction();
        try {
            foreach ($groups as $group) {
                foreach ($group['duplicate_ids'] as $duplicateId) {
                    $context = $this->retireExplicitFile((int)$group['canonical_id'], (int)$duplicateId);
                    $this->mergeReconciliation($reconciliation, $context);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $this->enqueueReconciliation($reconciliation);
        return ['retired' => $total, 'groups' => count($groups)];
    }

    /**
     * Resolve each selected Keep file to its current verified GUID group and retire all other active verified rows.
     *
     * @param list<int> $canonicalIds
     * @return array{retired:int,groups:int}
     */
    public function keepCanonicalFiles(array $canonicalIds, int $maxRetire): array
    {
        if ($canonicalIds === []) {
            throw new RuntimeException('Select at least one file to Keep.');
        }

        $groups = $this->keepGroups($canonicalIds);
        if ($groups === []) {
            throw new RuntimeException('The selected GUID group no longer has another active duplicate to retire.');
        }

        $total = array_sum(array_map(
            static fn(array $group): int => count($group['duplicate_ids']),
            $groups
        ));
        if ($total > $maxRetire) {
            throw new RuntimeException(
                'The selected Keep choices would retire ' . $total
                . ' files. Process at most ' . $maxRetire . ' files at once.'
            );
        }

        $reconciliation = [];
        $this->db->beginTransaction();
        try {
            foreach ($groups as $group) {
                foreach ($group['duplicate_ids'] as $duplicateId) {
                    $context = $this->retireKeepFile((int)$group['canonical_id'], (int)$duplicateId);
                    $this->mergeReconciliation($reconciliation, $context);
                }
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        $this->enqueueReconciliation($reconciliation);
        return ['retired' => $total, 'groups' => count($groups)];
    }

    private function validGuid(string $guid): bool
    {
        $compact = preg_replace('/[^A-Fa-f0-9]/', '', trim($guid)) ?? '';
        return strlen($compact) === 32 && preg_match('/^0+$/', $compact) !== 1;
    }

    /** @param list<int> $canonicalIds @return list<array{canonical_id:int,duplicate_ids:list<int>}> */
    private function keepGroups(array $canonicalIds): array
    {
        $groups = [];
        $seenGroups = [];
        foreach ($canonicalIds as $canonicalId) {
            $canonical = \catalog_one(
                $this->db,
                'SELECT id,game_id,package_guid FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
                [$canonicalId]
            );
            if (!$canonical) {
                throw new RuntimeException('The selected Keep file #' . $canonicalId . ' is no longer an active verified file.');
            }

            $guid = (string)($canonical['package_guid'] ?? '');
            if (!$this->validGuid($guid)) {
                throw new RuntimeException('The selected Keep file #' . $canonicalId . ' does not have a valid package GUID.');
            }

            $gameId = (int)$canonical['game_id'];
            $groupKey = $gameId . ':' . strtoupper($guid);
            if (isset($seenGroups[$groupKey])) {
                continue;
            }
            $seenGroups[$groupKey] = true;

            $duplicateIds = array_map(
                static fn(array $row): int => (int)$row['id'],
                \catalog_all(
                    $this->db,
                    'SELECT id FROM ue_files WHERE game_id=? AND package_guid=? AND scan_status="verified" AND id<>? ORDER BY id',
                    [$gameId, $guid, $canonicalId]
                )
            );
            if ($duplicateIds !== []) {
                $groups[] = ['canonical_id' => $canonicalId, 'duplicate_ids' => $duplicateIds];
            }
        }
        return $groups;
    }

    /** @return array{game_id:int,file_ids:list<int>,package_names:list<string>} */
    private function retireExplicitFile(int $canonicalId, int $duplicateId): array
    {
        if ($canonicalId === $duplicateId) {
            throw new RuntimeException('The canonical and duplicate file IDs must differ.');
        }

        $groupRows = \catalog_all(
            $this->db,
            'SELECT id, game_id, package_guid FROM ue_files WHERE id IN (?,?)',
            [$canonicalId, $duplicateId]
        );
        if (count($groupRows) !== 2) {
            throw new RuntimeException('File ' . $duplicateId . ' is not in the same valid GUID group as canonical file ' . $canonicalId);
        }
        $a = $groupRows[0];
        $b = $groupRows[1];
        $guidA = (string)($a['package_guid'] ?? '');
        $guidB = (string)($b['package_guid'] ?? '');
        if ((int)$a['game_id'] !== (int)$b['game_id'] || !$this->validGuid($guidA) || $guidA !== $guidB) {
            throw new RuntimeException('File ' . $duplicateId . ' is not in the same valid GUID group as canonical file ' . $canonicalId);
        }

        $files = \catalog_all(
            $this->db,
            'SELECT id,game_id,package_name FROM ue_files WHERE id IN (?,?)',
            [$canonicalId, $duplicateId]
        );
        $byId = [];
        foreach ($files as $file) {
            $byId[(int)$file['id']] = $file;
        }
        $canonical = $byId[$canonicalId] ?? null;
        $duplicate = $byId[$duplicateId] ?? null;
        if (!$canonical || !$duplicate) {
            throw new RuntimeException('The selected duplicate files disappeared before retirement.');
        }

        $this->moveLocationsAndMarkDuplicate($canonicalId, $duplicateId);
        return [
            'game_id' => (int)$canonical['game_id'],
            'file_ids' => [$canonicalId, $duplicateId],
            'package_names' => array_values(array_unique([
                (string)$canonical['package_name'],
                (string)$duplicate['package_name'],
            ])),
        ];
    }

    /** @return array{game_id:int,file_ids:list<int>,package_names:list<string>} */
    private function retireKeepFile(int $canonicalId, int $duplicateId): array
    {
        $rows = \catalog_all(
            $this->db,
            'SELECT id,game_id,package_guid,package_name FROM ue_files WHERE id IN (?,?) AND scan_status="verified"',
            [$canonicalId, $duplicateId]
        );
        if (count($rows) !== 2) {
            throw new RuntimeException('A selected duplicate group changed before it could be processed.');
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }
        $canonical = $byId[$canonicalId] ?? null;
        $duplicate = $byId[$duplicateId] ?? null;
        if (!$canonical || !$duplicate) {
            throw new RuntimeException('A selected duplicate group could not be verified.');
        }

        $guid = (string)($canonical['package_guid'] ?? '');
        if ((int)$canonical['game_id'] !== (int)$duplicate['game_id']
            || !$this->validGuid($guid)
            || $guid !== (string)($duplicate['package_guid'] ?? '')) {
            throw new RuntimeException('File #' . $duplicateId . ' is not in the selected Keep file GUID group.');
        }

        $this->moveLocationsAndMarkDuplicate($canonicalId, $duplicateId);
        return [
            'game_id' => (int)$canonical['game_id'],
            'file_ids' => [$canonicalId, $duplicateId],
            'package_names' => array_values(array_unique([
                (string)$canonical['package_name'],
                (string)$duplicate['package_name'],
            ])),
        ];
    }

    private function moveLocationsAndMarkDuplicate(int $canonicalId, int $duplicateId): void
    {
        $locations = \catalog_all($this->db, 'SELECT * FROM ue_file_locations WHERE file_id=?', [$duplicateId]);
        $insert = $this->db->prepare(
            'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) '
            . 'VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE '
            . 'exists_in_source=VALUES(exists_in_source),last_seen_at=VALUES(last_seen_at)'
        );
        foreach ($locations as $location) {
            $insert->execute([
                $canonicalId,
                (int)$location['source_id'],
                (string)$location['source_relative_path'],
                (int)$location['exists_in_source'],
                $location['last_seen_at'],
            ]);
        }

        $this->db->prepare(
            'UPDATE ue_files SET scan_status="duplicate",scan_notes=CONCAT(COALESCE(scan_notes,""),?) WHERE id=?'
        )->execute([
            "\nRetired as duplicate of file ID " . $canonicalId . ' on ' . date('Y-m-d H:i:s'),
            $duplicateId,
        ]);
    }

    /** @param array<string,mixed> $reconciliation @param array{game_id:int,file_ids:list<int>,package_names:list<string>} $context */
    private function mergeReconciliation(array &$reconciliation, array $context): void
    {
        $key = (string)$context['game_id'];
        $reconciliation[$key]['game_id'] = $context['game_id'];
        foreach ($context['file_ids'] as $id) {
            $reconciliation[$key]['file_ids'][$id] = true;
        }
        foreach ($context['package_names'] as $name) {
            $reconciliation[$key]['package_names'][strtolower($name)] = $name;
        }
    }

    /** @param array<string,mixed> $reconciliation */
    private function enqueueReconciliation(array $reconciliation): void
    {
        foreach ($reconciliation as $context) {
            foreach (array_keys((array)($context['file_ids'] ?? [])) as $fileId) {
                CatalogProjectionReconciliationQueue::enqueue(
                    $this->db,
                    (int)$fileId,
                    [(int)$context['game_id']],
                    array_values((array)($context['package_names'] ?? [])),
                    $this->config
                );
            }
        }
    }
}
