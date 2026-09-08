<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Download.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportSettingsService;
use UnrealDb\Catalog\Infrastructure\Downloads\PdoCatalogPackageExportPlanner;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

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
    if ($mode === 'disabled') {
        return '<span class="dep missing">disabled</span>';
    }
    if ($mode === 'protected_local') {
        return '<span class="dep resolved">protected local stream</span>';
    }
    $link = external_active_link_for_file($db, $fileId);
    if ($link) {
        return '<span class="dep resolved">external ready</span><br><span class="small">' . catalog_h($link['provider_name']) . '</span>';
    }
    if (external_queue_exists($db, $fileId)) {
        return '<span class="dep package_only">external queued</span>';
    }
    return '<span class="dep missing">external missing</span>';
}

try {
    catalog_start_session();
    $config = catalog_config();
    $db = catalog_db($config);
    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $packageSettings = new CatalogPackageExportSettingsService($db);
    $game = $packageSettings->game((int)$file['game_id']);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }

    $settings = $packageSettings->settings();
    $formats = $packageSettings->availableFormats($game, $settings);
    $labels = $packageSettings->formatLabels();
    $defaultFormat = $packageSettings->defaultFormat($game, $settings);
    if (!in_array($defaultFormat, $formats, true)) {
        $defaultFormat = $formats[0] ?? '';
    }
    $requestedFormat = strtolower(trim((string)($_GET['format'] ?? '')));
    if ($requestedFormat !== '' && in_array($requestedFormat, $formats, true)) {
        $defaultFormat = $requestedFormat;
    }
    $formName = substr(trim((string)($_GET['name'] ?? catalog_clean_unreal_package_stem((string)$file['package_name']))), 0, 160);
    $formVersion = substr(trim((string)($_GET['version'] ?? '1.0')), 0, 80);
    $formAuthor = substr(trim((string)($_GET['author'] ?? $settings['default_author'])), 0, 160);
    $formDependencies = !isset($_GET['dependencies']) || (string)$_GET['dependencies'] !== '0';
    $formAllowIncomplete = $settings['allow_incomplete'] && (string)($_GET['allow_incomplete'] ?? '0') === '1';

    $dependencySource = PdoDependencyReadSource::sql($db);
    $depCount = (int)(catalog_one(
        $db,
        'SELECT COUNT(DISTINCT rf.id) c FROM ' . $dependencySource . ' d '
        . 'JOIN ue_files rf ON rf.id=d.resolved_file_id '
        . 'WHERE d.file_id=? AND d.status IN ("resolved","package_only")',
        [$id]
    )['c'] ?? 0);

    catalog_head('Download');
    catalog_page_header(
        'Download ' . catalog_clean_unreal_filename((string)$file['original_name']),
        (string)$game['name'] . ' · ' . (string)$game['engine_key'],
        ['File information' => 'file-info.php?id=' . (int)$file['id'], 'Examine package' => 'file-examine.php?id=' . (int)$file['id']]
    );

    echo '<div class="card"><h2>Individual file</h2><p><strong>' . catalog_h($file['package_name']) . '</strong><br>' . catalog_h(catalog_clean_unreal_filename((string)$file['original_name'])) . '</p>';
    echo '<p class="muted">Public individual-file downloads use the protected download controller by default, so the physical catalogue-storage path is never exposed. External-mirror-only mode remains available as an administrator choice. Base-game protection is always enforced.</p>';
    echo '<div class="ui-inline-actions">' . CatalogUi::iconButton([
        'label' => 'Download ' . catalog_clean_unreal_filename((string)$file['original_name']),
        'icon' => '⇩',
        'href' => 'download.php?id=' . (int)$file['id'],
        'size' => 'sm',
        'variant' => 'primary',
    ]);
    if ($settings['enabled'] && $settings['dependency_zip_enabled'] && external_public_download_mode($db) !== 'external_mirror_only') {
        echo CatalogUi::button('Queue dependency ZIP', ['href' => 'download-package.php?id=' . (int)$file['id'] . '&format=dependency_zip&dependencies=1']);
    }
    echo '</div></div>';

    echo '<div class="card"><h2>Create mod/dependency package</h2>';
    if (!$settings['enabled']) {
        echo '<p class="muted">Generated package downloads are disabled by the administrator.</p>';
    } elseif (!$formats) {
        echo '<p class="muted">No generated package format is enabled for this game.</p>';
    } else {
        $preview = null;
        $previewError = null;
        try {
            $preview = (new PdoCatalogPackageExportPlanner($db, $config))->plan(
                $id,
                $defaultFormat,
                true,
                $settings
            );
        } catch (Throwable $previewException) {
            $previewError = $previewException->getMessage();
        }

        echo '<form id="generated-package-options-form" method="get" action="download-package.php" '
            . 'data-lookup-endpoint="generated-package-job.php" '
            . 'data-csrf="' . catalog_h(catalog_csrf('package-generation')) . '">';
        echo '<input type="hidden" name="id" value="' . (int)$file['id'] . '">';
        echo '<input type="hidden" name="file_id" value="' . (int)$file['id'] . '">';
        echo '<table><tr><th>Format</th><td><select name="format">';
        foreach ($formats as $format) {
            echo '<option value="' . catalog_h($format) . '"' . ($format === $defaultFormat ? ' selected' : '') . '>' . catalog_h($labels[$format] ?? $format) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th>Package name</th><td><input name="name" value="' . catalog_h($formName) . '" style="min-width:360px"></td></tr>';
        echo '<tr><th>Version</th><td><input name="version" value="' . catalog_h($formVersion) . '" style="width:120px"></td></tr>';
        echo '<tr><th>Author</th><td><input name="author" value="' . catalog_h($formAuthor) . '" style="min-width:360px"></td></tr>';
        echo '<tr><th>Dependencies</th><td><input type="hidden" name="dependencies" value="0"><label><input type="checkbox" name="dependencies" value="1"'
            . ($formDependencies ? ' checked' : '') . '> Include resolved dependencies'
            . ($settings['include_transitive'] ? ' transitively' : '') . '</label></td></tr>';
        if ($settings['allow_incomplete']) {
            echo '<tr><th>Incomplete package</th><td><label><input type="checkbox" name="allow_incomplete" value="1"'
                . ($formAllowIncomplete ? ' checked' : '')
                . '> Continue when genuinely missing dependencies cannot be included</label></td></tr>';
        }
        echo '</table><p><button id="package-generate-button" class="primary">Queue package build</button></p>'
            . '<div id="package-existing-build-status" class="muted small"></div></form>';
        echo '<p class="muted small">Equivalent queued/running builds are reused rather than queued twice. '
            . 'A completed artifact is reused until it expires. Package generation uses its own dedicated worker queue.</p>';

        if ($preview !== null) {
            echo '<div class="grid">';
            catalog_stat_card('Files', (int)$preview['file_count'], 'Selected file plus available dependency closure');
            catalog_stat_card('Payload', catalog_bytes((int)$preview['total_bytes']));
            catalog_stat_card('Base-game excluded', count($preview['blocked']), 'Indexed dependencies that will not be redistributed');
            catalog_stat_card('Missing', count($preview['missing']), 'Only genuinely missing dependency objects block a complete build');
            catalog_stat_card('Package-level matches', count($preview['package_only']), 'The matched provider package is included; these no longer block generation');
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
    echo '<tr><td class="mono"><a href="file-info.php?id=' . (int)$file['id'] . '">' . catalog_h($file['package_name']) . '</a></td><td><a href="file-examine.php?id=' . (int)$file['id'] . '">' . catalog_h(catalog_clean_unreal_filename((string)$file['original_name'])) . '</a></td><td>' . render_public_download_status($db, (int)$file['id']) . '</td><td>' . render_availability($db, (int)$file['id']) . '</td></tr></table></div>';

    $deps = catalog_all(
        $db,
        'SELECT DISTINCT rf.id,rf.package_name,rf.original_name,rf.file_size,rf.md5,rf.sha1,'
        . 'rf.package_guid,rf.is_compressed,d.status FROM ' . $dependencySource . ' d '
        . 'JOIN ue_files rf ON rf.id=d.resolved_file_id '
        . 'WHERE d.file_id=? AND d.status IN ("resolved","package_only") '
        . 'ORDER BY rf.package_name,rf.original_name',
        [$id]
    );
    echo '<div class="card"><h2>Resolved dependency files (' . $depCount . ')</h2>';
    if (!$deps) {
        echo '<p class="muted">No resolved dependency files are available for this package yet.</p>';
    } else {
        echo '<table><tr><th>Package</th><th>File</th><th>Identity</th><th>Size</th><th>Match</th><th>Public download</th><th>Availability</th><th>Actions</th></tr>';
        foreach ($deps as $dep) {
            echo '<tr><td class="mono"><a href="file-info.php?id=' . (int)$dep['id'] . '">' . catalog_h($dep['package_name']) . '</a></td><td><a href="file-examine.php?id=' . (int)$dep['id'] . '">' . catalog_h(catalog_clean_unreal_filename((string)$dep['original_name'])) . '</a></td><td>' . CatalogUi::identity((string)$dep['package_guid'], (string)$dep['md5'], (string)$dep['sha1']) . '</td><td>' . catalog_h(catalog_bytes((int)$dep['file_size'])) . '</td><td><span class="dep ' . catalog_h((string)$dep['status']) . '">' . catalog_h((string)$dep['status']) . '</span></td><td>' . render_public_download_status($db, (int)$dep['id']) . '</td><td>' . render_availability($db, (int)$dep['id']) . '</td><td>' . CatalogUi::iconButton([
                'label' => 'Download ' . catalog_clean_unreal_filename((string)$dep['original_name']),
                'icon' => '⇩',
                'href' => 'download.php?id=' . (int)$dep['id'],
                'size' => 'sm',
            ]) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $missing = catalog_all(
        $db,
        'SELECT required_package,required_object_path,status FROM ' . $dependencySource . ' d '
        . 'WHERE d.file_id=? AND d.status IN ("missing","package_only") '
        . 'ORDER BY required_package,required_object_path LIMIT 500',
        [$id]
    );
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

    echo '<script src="assets/generated-package-options.js"></script>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
