<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';
require_once __DIR__ . '/lib/ModPackageBuilder.php';

function render_availability(PDO $db, int $fileId): string
{
    $locations = catalog_all($db, 'SELECT s.name source_name, s.source_type, l.source_relative_path FROM ue_file_locations l JOIN ue_sources s ON s.id=l.source_id WHERE l.file_id=? AND l.exists_in_source=1 ORDER BY s.name, l.source_relative_path', [$fileId]);
    if (!$locations) {
        return '<span class="muted">catalog storage only</span>';
    }

    $out = [];
    foreach ($locations as $loc) {
        $out[] = catalog_h($loc['source_name']) . ' <span class="muted">(' . catalog_h($loc['source_type']) . ')</span><br><span class="mono small">' . catalog_h($loc['source_relative_path']) . '</span>';
    }
    return implode('<br>', $out);
}

function render_public_download_status(PDO $db, int $fileId): string
{
    $mode = external_public_download_mode($db);
    if ($mode === 'local_direct') {
        return '<span class="dep resolved">direct local</span>';
    }
    if ($mode === 'disabled') {
        return '<span class="dep missing">disabled</span>';
    }
    $link = external_active_link_for_file($db, $fileId);
    if ($link) {
        return '<span class="dep resolved">external ready</span><br><span class="small">' . catalog_h($link['provider_name']) . '</span>';
    }
    if (external_queue_exists($db, $fileId)) {
        return '<span class="dep package_only">mirror queued</span>';
    }
    return $mode === 'external_mirror_preferred' ? '<span class="dep package_only">external preferred</span>' : '<span class="dep missing">external missing</span>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }
    $game = modpkg_game_row($db, (int)$file['game_id']);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }

    $settings = modpkg_settings($db);
    $formats = modpkg_available_formats($game, $settings);
    $labels = modpkg_format_labels();
    $defaultFormat = modpkg_default_format($game, $settings);
    if (!in_array($defaultFormat, $formats, true)) {
        $defaultFormat = $formats[0] ?? '';
    }

    $depCount = (int)(catalog_one($db, 'SELECT COUNT(DISTINCT rf.id) c FROM ue_dependencies d JOIN ue_files rf ON rf.id=d.resolved_file_id WHERE d.file_id=? AND d.status IN ("resolved","package_only")', [$id])['c'] ?? 0);

    catalog_head('Download');
    catalog_page_header(
        'Download ' . catalog_clean_unreal_filename((string)$file['original_name']),
        (string)$game['name'] . ' · ' . (string)$game['engine_key'],
        ['File information' => 'file-info.php?id=' . (int)$file['id'], 'Examine package' => 'file-examine.php?id=' . (int)$file['id']]
    );

    echo '<div class="card"><h2>Individual file</h2><p><strong>' . catalog_h($file['package_name']) . '</strong><br>' . catalog_h(catalog_clean_unreal_filename((string)$file['original_name'])) . '</p>';
    echo '<p class="muted">Public download mode: <span class="mono">' . catalog_h(external_public_download_mode($db)) . '</span>. Base-game protection and the configured download mode are enforced by the download controller.</p>';
    echo '<p><a class="button primary" href="download.php?id=' . (int)$file['id'] . '">Download selected file</a>';
    if ($settings['enabled'] && $settings['dependency_zip_enabled'] && external_public_download_mode($db) !== 'external_mirror') {
        echo ' <a class="button" href="download-package.php?id=' . (int)$file['id'] . '&amp;format=dependency_zip&amp;dependencies=1">Queue dependency ZIP</a>';
    }
    echo '</p></div>';

    echo '<div class="card"><h2>Create mod/dependency package</h2>';
    if (!$settings['enabled']) {
        echo '<p class="muted">Generated package downloads are disabled by the administrator.</p>';
    } elseif (!$formats) {
        echo '<p class="muted">No generated package format is enabled for this game.</p>';
    } elseif (external_public_download_mode($db) === 'external_mirror') {
        echo '<p class="muted">Generated packages are unavailable while public downloads use external-mirror-only mode.</p>';
    } else {
        $preview = null;
        $previewError = null;
        try {
            $preview = modpkg_plan($db, $config, $id, $defaultFormat, true, $settings);
        } catch (Throwable $previewException) {
            $previewError = $previewException->getMessage();
        }

        echo '<form method="get" action="download-package.php">';
        echo '<input type="hidden" name="id" value="' . (int)$file['id'] . '">';
        echo '<table><tr><th>Format</th><td><select name="format">';
        foreach ($formats as $format) {
            echo '<option value="' . catalog_h($format) . '"' . ($format === $defaultFormat ? ' selected' : '') . '>' . catalog_h($labels[$format] ?? $format) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Package name</th><td><input name="name" value="' . catalog_h(catalog_clean_unreal_package_stem((string)$file['package_name'])) . '" style="min-width:360px"></td></tr>';
        echo '<tr><th>Version</th><td><input name="version" value="1.0" style="width:120px"></td></tr>';
        echo '<tr><th>Author</th><td><input name="author" value="' . catalog_h($settings['default_author']) . '" style="min-width:360px"></td></tr>';
        echo '<tr><th>Dependencies</th><td><input type="hidden" name="dependencies" value="0"><label><input type="checkbox" name="dependencies" value="1" checked> Include resolved dependencies' . ($settings['include_transitive'] ? ' transitively' : '') . '</label></td></tr>';
        if ($settings['allow_incomplete']) {
            echo '<tr><th>Incomplete package</th><td><label><input type="checkbox" name="allow_incomplete" value="1"> Continue when dependencies are missing or package-only</label></td></tr>';
        }
        echo '</table><p><button class="primary">Queue package build</button></p></form>';
        echo '<p class="muted small">The package is built and validated by the background worker. The progress page can be closed and reopened; completed artifacts are available to the initiating browser session for a limited time.</p>';

        if ($preview !== null) {
            echo '<div class="grid">';
            catalog_stat_card('Files', (int)$preview['file_count'], 'Selected file plus dependency closure');
            catalog_stat_card('Payload', catalog_bytes((int)$preview['total_bytes']));
            catalog_stat_card('Base-game excluded', count($preview['blocked']), 'Indexed dependencies that will not be redistributed');
            catalog_stat_card('Unresolved/package-only', count($preview['missing']) + count($preview['package_only']), 'Generation stops unless incomplete export is explicitly allowed');
            echo '</div>';
            $inferred = array_filter($preview['files'], static fn(array $row): bool => !empty($row['install_path_inferred']));
            if ($inferred) {
                echo '<p class="muted small">' . count($inferred) . ' destination path(s) were inferred from the engine/file type because no usable game-relative source path was recorded.</p>';
            }
        } elseif ($previewError !== null) {
            echo '<p class="dep missing">Preview unavailable: ' . catalog_h($previewError) . '</p>';
        }
    }
    echo '</div>';

    echo '<div class="card"><h2>Selected file availability</h2><table><tr><th>Package</th><th>File</th><th>Public download</th><th>Availability</th></tr>';
    echo '<tr><td class="mono">' . catalog_h($file['package_name']) . '</td><td>' . catalog_h(catalog_clean_unreal_filename((string)$file['original_name'])) . '</td><td>' . render_public_download_status($db, (int)$file['id']) . '</td><td>' . render_availability($db, (int)$file['id']) . '</td></tr></table></div>';

    $deps = catalog_all($db, 'SELECT DISTINCT rf.id, rf.package_name, rf.original_name, rf.file_size, rf.md5, rf.package_guid, rf.is_compressed, d.status FROM ue_dependencies d JOIN ue_files rf ON rf.id=d.resolved_file_id WHERE d.file_id=? AND d.status IN ("resolved","package_only") ORDER BY rf.package_name, rf.original_name', [$id]);
    echo '<div class="card"><h2>Resolved dependency files (' . $depCount . ')</h2>';
    if (!$deps) {
        echo '<p class="muted">No resolved dependency files are available for this package yet.</p>';
    } else {
        echo '<table><tr><th>Package</th><th>File</th><th>Identity</th><th>Size</th><th>Match</th><th>Public download</th><th>Availability</th><th>Download</th></tr>';
        foreach ($deps as $dep) {
            echo '<tr><td class="mono">' . catalog_h($dep['package_name']) . '</td><td>' . catalog_h(catalog_clean_unreal_filename((string)$dep['original_name'])) . '</td><td><span class="mono small">MD5 ' . catalog_h($dep['md5']) . '</span><br><span class="mono small">GUID ' . catalog_h($dep['package_guid']) . '</span></td><td>' . catalog_h(catalog_bytes((int)$dep['file_size'])) . '</td><td><span class="dep ' . catalog_h((string)$dep['status']) . '">' . catalog_h((string)$dep['status']) . '</span></td><td>' . render_public_download_status($db, (int)$dep['id']) . '</td><td>' . render_availability($db, (int)$dep['id']) . '</td><td><a class="button" href="download.php?id=' . (int)$dep['id'] . '">download</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $missing = catalog_all($db, 'SELECT required_package, required_object_path, status FROM ue_dependencies WHERE file_id=? AND status IN ("missing","package_only") ORDER BY required_package, required_object_path LIMIT 500', [$id]);
    echo '<div class="card"><h2>Missing or package-only dependency objects</h2>';
    if (!$missing) {
        echo '<p class="muted">No missing or package-only dependency objects.</p>';
    } else {
        echo '<table><tr><th>Status</th><th>Required package</th><th>Required object</th></tr>';
        foreach ($missing as $row) {
            echo '<tr><td><span class="dep ' . catalog_h((string)$row['status']) . '">' . catalog_h((string)$row['status']) . '</span></td><td class="mono">' . catalog_h($row['required_package']) . '</td><td class="mono path">' . catalog_h($row['required_object_path']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
