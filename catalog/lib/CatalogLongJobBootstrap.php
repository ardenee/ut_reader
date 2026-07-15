<?php
declare(strict_types=1);

/**
 * Loads page-specific long-job controllers without coupling progress UI to the
 * database/parser services. Add future long-running admin pages to this map.
 */
function catalog_long_job_bootstrap_install(): void
{
    static $installed = false;
    if ($installed) {
        return;
    }
    $installed = true;

    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }
    if ((string)($_GET['progress'] ?? '') !== '') {
        return;
    }

    $scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $pageControllers = [
        'source-identity-repair.php' => 'source-identity-repair-progress.js',
    ];
    $controller = $pageControllers[$scriptName] ?? '';
    if ($controller === '') {
        return;
    }

    $assetRoot = dirname(__DIR__) . '/assets/';
    $commonVersion = is_file($assetRoot . 'catalog-long-job.js')
        ? (string)filemtime($assetRoot . 'catalog-long-job.js')
        : '1';
    $controllerVersion = is_file($assetRoot . $controller)
        ? (string)filemtime($assetRoot . $controller)
        : '1';

    ob_start(static function (string $html) use ($controller, $commonVersion, $controllerVersion): string {
        $injection = '<script src="assets/catalog-long-job.js?v=' . rawurlencode($commonVersion) . '"></script>'
            . '<script src="assets/' . htmlspecialchars($controller, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '?v=' . rawurlencode($controllerVersion) . '"></script>';
        if (str_contains($html, '</body>')) {
            return str_replace('</body>', $injection . '</body>', $html);
        }
        return $html . $injection;
    });
}

catalog_long_job_bootstrap_install();
