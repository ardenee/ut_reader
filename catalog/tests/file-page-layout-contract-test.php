<?php
declare(strict_types=1);

function file_page_layout_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = file_get_contents(__DIR__ . '/../assets/catalog-layout-fixes.js');
file_page_layout_expect(is_string($client), 'Could not read catalog layout fixes client.');
foreach ([
    '#game-files-table th:first-child',
    'white-space: normal !important',
    '.file-info-scan-notes',
    'white-space: pre-wrap !important',
    "page !== 'file-info.php'",
    "page !== 'file-examine.php'",
    "return header.textContent.trim().toLowerCase() === 'import result'",
    "actions.querySelectorAll('a[href*=\"pak-info.php\"]')",
    "download.className = 'pak-source-download-icon'",
    "new URL('download-icon.svg', script.src)",
] as $fragment) {
    file_page_layout_expect(str_contains($client, $fragment), 'Catalog layout fixes are missing: ' . $fragment);
}

$hook = file_get_contents(__DIR__ . '/../src/Presentation/Http/LegacySupportHooks.php');
file_page_layout_expect(is_string($hook), 'Could not read legacy support hooks.');
file_page_layout_expect(str_contains($hook, 'registerLayoutFixAssets'), 'Catalog layout fixes are not loaded globally.');
file_page_layout_expect(str_contains($hook, 'catalog-layout-fixes.js'), 'Catalog layout fix asset is not registered.');

$icon = file_get_contents(__DIR__ . '/../assets/download-icon.svg');
file_page_layout_expect(is_string($icon), 'Compact download icon is missing.');
file_page_layout_expect(str_contains($icon, '<svg'), 'Compact download icon is not SVG content.');

echo "File page layout contract tests passed.\n";
