#!/usr/bin/env php
<?php
/**
 * Read-only contract for the server-rendered catalog component system.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Presentation\Ui\CatalogUi;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($root): string {
    $source = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($source) ? $source : '';
};

$status = CatalogUi::statusBadge('dead_letter');
$record(
    'status_badge_is_semantic',
    str_contains($status, 'ui-badge--danger')
        && str_contains($status, 'data-status="dead_letter"')
        && str_contains($status, '>Failed<'),
    'application statuses need one canonical tone and human-readable label mapping'
);

$live = CatalogUi::liveRegion('<unsafe>', ['priority' => 'assertive', 'tone' => 'danger', 'id' => 'test-live']);
$record(
    'live_region_is_accessible_and_escaped',
    str_contains($live, 'role="alert"')
        && str_contains($live, 'aria-live="assertive"')
        && str_contains($live, 'aria-atomic="true"')
        && str_contains($live, '&lt;unsafe&gt;')
        && !str_contains($live, '<unsafe>'),
    'async status text must be announced without allowing raw HTML injection'
);

$segmented = CatalogUi::segmentedControl([
    [
        'label' => 'Running',
        'value' => 'running',
        'active' => true,
        'count' => 4,
        'attributes' => ['data-status' => 'running'],
        'count_attributes' => ['data-status-count' => 'running'],
    ],
], ['label' => 'Job status']);
$record(
    'segmented_control_exposes_pressed_state',
    str_contains($segmented, 'role="group"')
        && str_contains($segmented, 'aria-label="Job status"')
        && str_contains($segmented, 'aria-pressed="true"')
        && str_contains($segmented, 'data-status-count="running"'),
    'filter/view controls must expose native button interaction plus current selection state'
);

$toolbar = CatalogUi::toolbar(
    CatalogUi::button('Refresh', ['attributes' => ['id' => 'refresh']]),
    '<span>Status</span>',
    ['label' => 'Queue controls']
);
$record(
    'toolbar_groups_commands',
    str_contains($toolbar, 'role="group"')
        && str_contains($toolbar, 'aria-label="Queue controls"')
        && str_contains($toolbar, 'id="refresh"')
        && str_contains($toolbar, 'ui-toolbar__aside'),
    'command groups need a reusable responsive container with an accessible name'
);

$background = $read('background-jobs.php');
$record(
    'background_jobs_uses_shared_primitives',
    str_contains($background, 'CatalogUi::toolbar(')
        && str_contains($background, 'CatalogUi::liveRegion(')
        && str_contains($background, 'CatalogUi::tableRegion(')
        && str_contains($background, 'CatalogUi::button('),
    'the high-complexity reference page must adopt the component system instead of remaining page-local markup'
);
$record(
    'background_jobs_table_is_accessible',
    str_contains($background, '<caption class="ui-sr-only">Background jobs for queue ')
        && str_contains($background, '<th scope="col">ID</th>')
        && str_contains($background, 'aria-label="Select all jobs on this page"'),
    'large async tables need a caption, scoped headers and named selection controls'
);

$css = $read('assets/catalog-ui-components.css');
$record(
    'new_components_are_responsive',
    str_contains($css, '.ui-toolbar__aside')
        && str_contains($css, '.ui-segmented__item[aria-pressed="true"]')
        && str_contains($css, '.ui-live-region')
        && str_contains($css, '@media (max-width: 720px)'),
    'shared components need common responsive states rather than page-specific media-query copies'
);

$catalogUi = $read('src/Presentation/Ui/CatalogUi.php');
$record(
    'catalog_ui_facade_exposes_new_components',
    str_contains($catalogUi, 'function statusBadge(')
        && str_contains($catalogUi, 'function liveRegion(')
        && str_contains($catalogUi, 'function segmentedControl(')
        && str_contains($catalogUi, 'function toolbar('),
    'page authors should use the same facade as the established design-system components'
);

$syntaxTargets = [
    'background-jobs.php',
    'src/Presentation/Ui/CatalogUi.php',
    'src/Presentation/Ui/Component/Toolbar.php',
    'src/Presentation/Ui/Component/SegmentedControl.php',
    'src/Presentation/Ui/Component/LiveRegion.php',
    'src/Presentation/Ui/Component/StatusBadge.php',
    'bin/verify-frontend-component-system.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable; run php -l manually';
} else {
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' missing';
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
