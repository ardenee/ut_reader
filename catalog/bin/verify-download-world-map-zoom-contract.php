<?php
/** Static contract for download-log world-map zoom/pan controls. */
declare(strict_types=1);

$path = dirname(__DIR__) . '/assets/catalog-ui.js';
$content = is_file($path) ? file_get_contents($path) : false;

$checks = [
    'map controls container' => 'download-world-map-controls',
    'zoom in control' => "controlButton('+', 'Zoom in')",
    'zoom out control' => "controlButton('−', 'Zoom out')",
    'reset control' => "controlButton('Reset', 'Reset map to the full world view')",
    'zoom uses SVG viewBox' => "svg.setAttribute('viewBox'",
    'reset restores base view' => 'function resetView()',
    'wheel zoom' => "stage.addEventListener('wheel'",
    'double click zoom' => "stage.addEventListener('dblclick'",
    'pointer drag pan' => "stage.addEventListener('pointermove'",
    'pointer capture' => 'stage.setPointerCapture(event.pointerId)',
    'world-bound clamping' => 'function clampView(candidate)',
    'maximum zoom bound' => 'var maxScale = 10;',
];

$failed = [];
if (!is_string($content)) {
    $failed[] = 'catalog-ui.js could not be read';
} else {
    foreach ($checks as $label => $needle) {
        if (!str_contains($content, $needle)) {
            $failed[] = $label;
        }
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Download world map zoom contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo 'Download world map zoom contract passed (' . count($checks) . " checks).\n";
