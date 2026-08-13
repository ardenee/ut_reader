<?php
/**
 * Static regression contract for cross-game dependency-closure imports.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$plannerPath = $root . '/src/Infrastructure/Unverified/CatalogCrossGameDependencyClosurePlanner.php';
$servicePath = $root . '/src/Infrastructure/Unverified/CatalogCrossGamePackageCopyService.php';
$planner = is_file($plannerPath) ? file_get_contents($plannerPath) : false;
$service = is_file($servicePath) ? file_get_contents($servicePath) : false;

$failed = [];
$require = static function (bool $condition, string $label) use (&$failed): void {
    if (!$condition) {
        $failed[] = $label;
    }
};

$require(is_string($planner), 'dependency closure planner exists');
$require(is_string($service), 'cross-game copy service exists');

if (is_string($planner)) {
    $require(str_contains($planner, 'PdoDependencyReadSource::sql($this->db)'), 'planner uses authoritative compact dependency source');
    $require(str_contains($planner, "if (\$status === 'common')"), 'planner excludes common dependencies from copy closure');
    $require(str_contains($planner, "if (\$status === 'missing' || \$resolvedFileId < 1)"), 'planner does not invent unresolved dependencies');
    $require(str_contains($planner, "if (\$status === 'package_only')"), 'planner preserves package-only dependency traversal');
    $require(str_contains($planner, 'provider_game_id') && str_contains($planner, '$sourceGameId'), 'planner keeps resolved providers inside the source game');
    $require(str_contains($planner, 'while ($queue !== [])'), 'planner traverses dependencies transitively');
    $require(str_contains($planner, 'array_reverse(array_values($dependencies))'), 'planner returns dependency-first queue order');
    $require(str_contains($planner, 'MAX_DEPENDENCY_FILES'), 'planner has a cycle/runaway safety bound');
}

if (is_string($service)) {
    $closurePos = strpos($service, 'CatalogCrossGameDependencyClosurePlanner');
    $rootQueuePos = strpos($service, '$rootQueued = $this->queueCandidate');
    $require($closurePos !== false && $rootQueuePos !== false && $closurePos < $rootQueuePos, 'service plans and queues dependencies before the selected root');
    $require(str_contains($service, "'cross_game_dependency_support' => \$dependencySupport"), 'import payload identifies dependency-support jobs');
    $require(str_contains($service, '$dependencyAlreadyPresent++'), 'already-present dependency bytes are skipped');
    $require(str_contains($service, "'cross-game-copy:' . hash("), 'shared dependency imports keep the normal cross-game dedupe key');
    $require(str_contains($service, "                5,\n                \$sourceFileId,\n                true"), 'dependency imports receive earlier queue priority');
    $require(str_contains($service, "            6,\n            \$sourceFileId,\n            false"), 'selected root is queued after dependency imports');
}

if ($failed !== []) {
    fwrite(STDERR, "Cross-game dependency closure contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Cross-game dependency closure contract passed.\n";
