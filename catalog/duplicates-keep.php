<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue;

catalog_start_session();

const DUPLICATES_KEEP_MAX_RETIRE = 950;

function duplicates_keep_return_url(): string
{
    $url = (string)($_POST['return_url'] ?? 'duplicates.php');
    $path = basename((string)(parse_url($url, PHP_URL_PATH) ?? ''));
    if ($path !== 'duplicates.php') {
        return 'duplicates.php';
    }
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
    return 'duplicates.php' . ($query !== '' ? '?' . $query : '');
}

function duplicates_keep_valid_guid(string $guid): bool
{
    $compact = preg_replace('/[^A-Fa-f0-9]/', '', trim($guid)) ?? '';
    return strlen($compact) === 32 && preg_match('/^0+$/', $compact) !== 1;
}

/** @return list<int> */
function duplicates_keep_selected_ids(): array
{
    $posted = $_POST['canonical_ids'] ?? null;
    if (!is_array($posted)) {
        return [];
    }

    return array_values(array_filter(
        array_unique(array_map('intval', array_values($posted))),
        static fn(int $id): bool => $id > 0
    ));
}

/** @return list<array{canonical_id:int,duplicate_ids:list<int>}> */
function duplicates_keep_groups(PDO $db, array $canonicalIds): array
{
    $groups = [];
    $seenGroups = [];

    foreach ($canonicalIds as $canonicalId) {
        $canonical = catalog_one(
            $db,
            'SELECT id,game_id,package_guid FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
            [$canonicalId]
        );
        if (!$canonical) {
            throw new RuntimeException('The selected Keep file #' . $canonicalId . ' is no longer an active verified file.');
        }

        $guid = (string)($canonical['package_guid'] ?? '');
        if (!duplicates_keep_valid_guid($guid)) {
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
            catalog_all(
                $db,
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
function duplicates_keep_retire_file(PDO $db, int $canonicalId, int $duplicateId): array
{
    $rows = catalog_all(
        $db,
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
        || !duplicates_keep_valid_guid($guid)
        || $guid !== (string)($duplicate['package_guid'] ?? '')) {
        throw new RuntimeException('File #' . $duplicateId . ' is not in the selected Keep file GUID group.');
    }

    $locations = catalog_all($db, 'SELECT * FROM ue_file_locations WHERE file_id=?', [$duplicateId]);
    $insertLocation = $db->prepare(
        'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) '
        . 'VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE '
        . 'exists_in_source=VALUES(exists_in_source),last_seen_at=VALUES(last_seen_at)'
    );
    foreach ($locations as $location) {
        $insertLocation->execute([
            $canonicalId,
            (int)$location['source_id'],
            (string)$location['source_relative_path'],
            (int)$location['exists_in_source'],
            $location['last_seen_at'],
        ]);
    }

    $db->prepare(
        'UPDATE ue_files SET scan_status="duplicate",scan_notes=CONCAT(COALESCE(scan_notes,""),?) WHERE id=?'
    )->execute([
        "\nRetired as duplicate of file ID " . $canonicalId . ' on ' . date('Y-m-d H:i:s'),
        $duplicateId,
    ]);

    return [
        'game_id' => (int)$canonical['game_id'],
        'file_ids' => [$canonicalId, $duplicateId],
        'package_names' => array_values(array_unique([
            (string)$canonical['package_name'],
            (string)$duplicate['package_name'],
        ])),
    ];
}

$returnUrl = duplicates_keep_return_url();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: duplicates.php');
        exit;
    }
    if (!catalog_support_is_admin()) {
        throw new RuntimeException('Admin required.');
    }

    catalog_check_csrf('duplicates');
    $canonicalIds = duplicates_keep_selected_ids();
    if ($canonicalIds === []) {
        throw new RuntimeException('Select at least one file to Keep.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $groups = duplicates_keep_groups($db, $canonicalIds);
    if ($groups === []) {
        throw new RuntimeException('The selected GUID group no longer has another active duplicate to retire.');
    }

    $totalRetire = array_sum(array_map(
        static fn(array $group): int => count($group['duplicate_ids']),
        $groups
    ));
    if ($totalRetire > DUPLICATES_KEEP_MAX_RETIRE) {
        throw new RuntimeException(
            'The selected Keep choices would retire ' . $totalRetire
            . ' files. Process at most ' . DUPLICATES_KEEP_MAX_RETIRE . ' files at once.'
        );
    }

    $reconciliation = [];
    $db->beginTransaction();
    try {
        foreach ($groups as $group) {
            foreach ($group['duplicate_ids'] as $duplicateId) {
                $context = duplicates_keep_retire_file($db, $group['canonical_id'], $duplicateId);
                $key = (string)$context['game_id'];
                $reconciliation[$key]['game_id'] = $context['game_id'];
                foreach ($context['file_ids'] as $id) {
                    $reconciliation[$key]['file_ids'][$id] = true;
                }
                foreach ($context['package_names'] as $name) {
                    $reconciliation[$key]['package_names'][strtolower($name)] = $name;
                }
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }

    foreach ($reconciliation as $context) {
        foreach (array_keys((array)($context['file_ids'] ?? [])) as $fileId) {
            CatalogProjectionReconciliationQueue::enqueue(
                $db,
                (int)$fileId,
                [(int)$context['game_id']],
                array_values((array)($context['package_names'] ?? [])),
                $config
            );
        }
    }

    $_SESSION['flash_duplicates'] = 'Kept ' . count($groups) . ' primary file(s) and retired '
        . $totalRetire . ' other active duplicate file(s).';
} catch (Throwable $error) {
    $_SESSION['flash_duplicates'] = $error->getMessage();
}

header('Location: ' . $returnUrl);
exit;
