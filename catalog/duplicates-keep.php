<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Handles the bulk Keep submission from the GUID duplicate manager.
 * Why: The HTTP route should validate transport input and delegate duplicate retirement to the shared maintenance service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogDuplicateRetirementService;

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
    $config = catalog_config();
    $db = catalog_db($config);
    $result = (new CatalogDuplicateRetirementService($db, $config))
        ->keepCanonicalFiles($canonicalIds, DUPLICATES_KEEP_MAX_RETIRE);

    $_SESSION['flash_duplicates'] = 'Kept ' . $result['groups'] . ' primary file(s) and retired '
        . $result['retired'] . ' other active duplicate file(s).';
} catch (Throwable $error) {
    $_SESSION['flash_duplicates'] = $error->getMessage();
}

header('Location: ' . $returnUrl);
exit;
