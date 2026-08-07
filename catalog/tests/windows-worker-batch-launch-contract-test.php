<?php
declare(strict_types=1);

function windows_worker_batch_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDetachedWorker.php');
windows_worker_batch_expect(is_string($source) && $source !== '', 'Detached worker source is missing.');

windows_worker_batch_expect(
    str_contains($source, 'spawnWindowsPool(')
        && str_contains($source, '$launchSpecs[] = [')
        && str_contains($source, 'foreach ($launchSpecs as $launch)')
        && str_contains($source, "'bypass_shell' => true")
        && str_contains($source, "'create_process_group' => true")
        && str_contains($source, '$activeLaunched === count($launchedSlots)')
        && !str_contains($source, 'if ($activeLaunched > 0 || $terminalLaunched === count($launchedSlots)'),
    'Windows detached workers are not batch-launched and verified as a complete pool.'
);

echo "Windows worker batch-launch contract test passed.\n";
