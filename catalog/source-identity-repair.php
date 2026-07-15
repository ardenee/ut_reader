<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';

const SOURCE_IDENTITY_REPAIR_LOCK = 'unrealdb_catalog_maintenance_write_v1';
const SOURCE_IDENTITY_REPAIR_LOCK_WAIT = 45;

/** @return mixed */
function source_identity_repair_with_lock(PDO $db, callable $operation): mixed
{
    $row = catalog_one(
        $db,
        'SELECT GET_LOCK(?, ?) acquired',
        [SOURCE_IDENTITY_REPAIR_LOCK, SOURCE_IDENTITY_REPAIR_LOCK_WAIT]
    );
    if ((int)($row['acquired'] ?? 0) !== 1) {
        throw new RuntimeException('Another catalog maintenance task is running. Try again after it finishes.');
    }

    try {
        return $operation();
    } finally {
        try {
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([SOURCE_IDENTITY_REPAIR_LOCK]);
        } catch (Throwable $releaseError) {
            error_log('[UnrealDB source identity repair] lock release failed: ' . $releaseError->getMessage());
        }
    }
}

function source_identity_repair_post_int(string $name): int
{
    $value = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
    if ($value === false || $value === null || $value < 1) {
        throw new RuntimeException('A valid ' . str_replace('_', ' ', $name) . ' is required.');
    }
    return (int)$value;
}

function source_identity_repair_get_int(string $name): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    return ($value === false || $value === null || $value < 1) ? 0 : (int)$value;
}

function source_identity_repair_source_path(PDO $db, array $file): string
{
    $path = catalog_source_identity_path((string)($file['source_relative_path'] ?? ''));
    if ($path !== '') {
        return $path;
    }

    $location = catalog_one(
        $db,
        'SELECT source_relative_path FROM ue_file_locations WHERE file_id=? AND source_relative_path<>"" ORDER BY id LIMIT 1',
        [(int)$file['id']]
    );
    return catalog_source_identity_path((string)($location['source_relative_path'] ?? ''));
}

/** @return list<array<string,mixed>> */
function source_identity_repair_audit(PDO $db, int $gameId): array
{
    $files = catalog_all(
        $db,
        'SELECT f.id,f.package_name,f.original_name,f.source_relative_path,f.detected_engine_key,p.engine_key profile_engine'
        . ' FROM ue_files f'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id'
        . ' WHERE f.game_id=? AND f.scan_status="verified"'
        . ' ORDER BY f.package_name,f.id',
        [$gameId]
    );

    $mismatches = [];
    foreach ($files as $file) {
        $engineKey = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        if ($engineKey === '') {
            $engineKey = strtoupper(trim((string)($file['profile_engine'] ?? '')));
        }
        $sourcePath = source_identity_repair_source_path($db, $file);
        $canonical = catalog_source_identity_package_name(
            $engineKey,
            $sourcePath,
            (string)$file['original_name']
        );
        if ($canonical === '' || strcasecmp((string)$file['package_name'], $canonical) === 0) {
            continue;
        }
        $file['canonical_package_name'] = $canonical;
        $file['canonical_source_path'] = $sourcePath;
        $mismatches[] = $file;
    }

    return $mismatches;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        throw new RuntimeException('Administrator login is required.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $message = '';
    $messageType = 'success';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('source-identity-repair');
        $operation = trim((string)($_POST['operation'] ?? ''));

        if ($operation === 'repair_file') {
            $fileId = source_identity_repair_post_int('file_id');
            $result = source_identity_repair_with_lock(
                $db,
                static fn(): array => catalog_source_identity_rebuild_file($db, $config, $fileId, null, true)
            );
            $message = $result['changed']
                ? 'Canonical database identity repaired: ' . $result['old_package_name'] . ' → ' . $result['new_package_name']
                    . '. Source-derived aliases: ' . $result['alias_count']
                    . '; dependency files refreshed: ' . $result['dependency_files_refreshed'] . '.'
                : 'This file already matches its mounted source path. No canonical database fields changed.';
        } elseif ($operation === 'repair_game') {
            $gameId = source_identity_repair_post_int('game_id');
            $summary = source_identity_repair_with_lock(
                $db,
                static function () use ($db, $config, $gameId): array {
                    $files = catalog_all(
                        $db,
                        'SELECT id,package_name FROM ue_files WHERE game_id=? AND scan_status="verified" ORDER BY package_name,id',
                        [$gameId]
                    );
                    $changed = 0;
                    $aliases = 0;
                    $failures = [];
                    foreach ($files as $file) {
                        try {
                            $result = catalog_source_identity_rebuild_file(
                                $db,
                                $config,
                                (int)$file['id'],
                                null,
                                false
                            );
                            if ($result['changed']) {
                                $changed++;
                            }
                            $aliases += (int)$result['alias_count'];
                        } catch (Throwable $error) {
                            $failures[] = (string)$file['package_name'] . ': ' . $error->getMessage();
                        }
                    }

                    /* One dependency pass after all canonical identities are committed. */
                    scanner_rebuild_game($db, $config, $gameId, null, 0, 100);
                    return [
                        'total' => count($files),
                        'changed' => $changed,
                        'aliases' => $aliases,
                        'failures' => $failures,
                    ];
                }
            );
            $message = 'Canonical identity repair completed: ' . $summary['changed'] . '/' . $summary['total']
                . ' files changed; ' . $summary['aliases'] . ' source-derived aliases retained; dependencies rebuilt once.';
            if ($summary['failures'] !== []) {
                $messageType = 'warning';
                $message .= ' Failures: ' . implode(' | ', array_slice($summary['failures'], 0, 10));
            }
        } else {
            throw new RuntimeException('Unknown source identity repair operation.');
        }
    }

    $games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name');
    $selectedGameId = source_identity_repair_get_int('game_id');
    if ($selectedGameId === 0 && $games !== []) {
        $selectedGameId = (int)$games[0]['id'];
    }
    $mismatches = $selectedGameId > 0 ? source_identity_repair_audit($db, $selectedGameId) : [];
    $csrf = catalog_csrf('source-identity-repair');

    catalog_head('Source Identity Repair');
    echo CatalogUi::pageHeader(
        'Source Identity Repair',
        'Recalculate canonical database package identities from mounted source paths. This does not alter display output or guess package names.',
        ['Back to games' => 'games.php']
    );

    if ($message !== '') {
        echo CatalogUi::alert($messageType, $message, 'Repair result');
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Repair one file</h2>'
        . '<p>Use the numeric file ID from file-info.php or file-examine.php.</p></div></div><div class="ui-section__body">';
    echo '<form method="post" class="form-grid" data-ui-loading-form>';
    echo '<input type="hidden" name="csrf" value="' . catalog_h($csrf) . '">';
    echo '<input type="hidden" name="operation" value="repair_file">';
    echo '<label>File ID <input type="number" min="1" name="file_id" required></label>';
    echo CatalogUi::button('Repair canonical identity', ['type' => 'submit']);
    echo '<span data-ui-loading-indicator>' . CatalogUi::loadingState('Repairing database identity…', true) . '</span>';
    echo '</form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Audit or repair a game</h2>'
        . '<p>The game repair rewrites derived identity fields, then performs one dependency-only pass. It does not reparse package bytes.</p></div></div><div class="ui-section__body">';
    echo '<form method="get" class="form-grid">';
    echo '<label>Game <select name="game_id">';
    foreach ($games as $game) {
        $id = (int)$game['id'];
        echo '<option value="' . $id . '"' . ($id === $selectedGameId ? ' selected' : '') . '>' . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>';
    echo CatalogUi::button('Audit canonical identities', ['type' => 'submit', 'variant' => 'secondary']);
    echo '</form>';

    if ($selectedGameId > 0) {
        echo '<form method="post" style="margin-top:12px" onsubmit="return confirm(\'Rewrite canonical identity fields for every verified file in this game and rebuild dependencies once?\');" data-ui-loading-form>';
        echo '<input type="hidden" name="csrf" value="' . catalog_h($csrf) . '">';
        echo '<input type="hidden" name="operation" value="repair_game">';
        echo '<input type="hidden" name="game_id" value="' . $selectedGameId . '">';
        echo CatalogUi::button('Repair this game from source paths', ['type' => 'submit']);
        echo '<span data-ui-loading-indicator>' . CatalogUi::loadingState('Repairing canonical database identities…', true) . '</span>';
        echo '</form>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Detected primary-path mismatches</h2>'
        . '<p>' . count($mismatches) . ' verified files have a stored package name that differs from the package name derived from their mounted source path.</p></div></div><div class="ui-section__body">';
    if ($mismatches === []) {
        echo CatalogUi::emptyState('No primary identity mismatches', 'All audited primary package names match their mounted source paths.', null, '✓');
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>File ID</th><th>File</th><th>Stored package</th><th>Source-derived package</th><th>Mounted source path</th></tr></thead><tbody>';
        foreach ($mismatches as $file) {
            $id = (int)$file['id'];
            echo '<tr>';
            echo '<td class="mono">' . $id . '</td>';
            echo '<td><a href="file-info.php?id=' . $id . '">' . catalog_h((string)$file['original_name']) . '</a></td>';
            echo '<td class="mono">' . catalog_h((string)$file['package_name']) . '</td>';
            echo '<td class="mono">' . catalog_h((string)$file['canonical_package_name']) . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$file['canonical_source_path']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Source Identity Repair Error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'Repair failed');
    catalog_foot();
}
