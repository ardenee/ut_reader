<?php
/**
 * Static regression contract for game/profiled import issue visibility and
 * duplicate-race handling in the file-centric Background Jobs ledger.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'file tree promotes profiled upload sources' => [
        $root . '/src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
        'PROFILED_UPLOAD_BATCH_JOB_TYPE',
    ],
    'file tree owns logical source-root expression' => [
        $root . '/src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php',
        'logicalRootExpression',
    ],
    'bulk/search scope includes profiled source jobs' => [
        $root . '/src/Infrastructure/Persistence/PdoBackgroundJobSearchScope.php',
        'SELECT profiled_source.*',
    ],
    'bulk/search scope uses authoritative profiled job type' => [
        $root . '/src/Infrastructure/Persistence/PdoBackgroundJobSearchScope.php',
        'JobType::PROFILED_UPLOAD_BATCH',
    ],
    'verified import adapter catches database duplicate races' => [
        $root . '/src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
        'catch (PDOException $error)',
    ],
    'verified import adapter recognizes game MD5 constraint' => [
        $root . '/src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
        'uq_ue_files_game_md5',
    ],
    'verified import adapter re-reads duplicate identity after race' => [
        $root . '/src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
        '$this->identity->findVerifiedDuplicate($gameId, $inspection, 0)',
    ],
    'verified identity lookup follows game plus MD5 unique rule' => [
        $root . '/src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php',
        'scan_status="verified" AND md5=?',
    ],
    'worker fingerprint tracks verified import adapter' => [
        $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
        '/src/Infrastructure/Import/CatalogPackageImporterAdapter.php',
    ],
    'worker fingerprint tracks verified identity repository' => [
        $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php',
        '/src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php',
    ],
];

$failed = [];
foreach ($checks as $label => [$path, $needle]) {
    $content = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($content) || !str_contains($content, $needle)) {
        $failed[] = $label;
    }
}

$queryPath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php';
$query = is_file($queryPath) ? file_get_contents($queryPath) : false;
if (!is_string($query) || !str_contains($query, 'profiled_parent')) {
    // The query names its lookup alias logical_root_parent; this check is kept
    // semantic below instead of relying on a particular alias spelling.
    if (!is_string($query) || !str_contains($query, 'logical_root_parent')) {
        $failed[] = 'logical source root must be proven through its persisted parent job';
    }
}

$scopePath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobSearchScope.php';
$scope = is_file($scopePath) ? file_get_contents($scopePath) : false;
if (!is_string($scope) || !str_contains($scope, 'profiled_parent.id=profiled_source.parent_job_id')) {
    $failed[] = 'bulk scope must preserve profiled source parent relationship';
}

$identityPath = $root . '/src/Infrastructure/Import/CatalogVerifiedPackageIdentityRepository.php';
$identity = is_file($identityPath) ? file_get_contents($identityPath) : false;
if (!is_string($identity)
    || str_contains($identity, "if (\$inspection->packageGuid !== '')")
    || str_contains($identity, 'AND package_guid=? AND md5=?')) {
    $failed[] = 'verified duplicate lookup must not narrow game+MD5 identity by package GUID';
}

if ($failed !== []) {
    fwrite(STDERR, "Profiled import issue ledger contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Profiled import issue ledger contract passed (" . (count($checks) + 3) . " checks).\n";
