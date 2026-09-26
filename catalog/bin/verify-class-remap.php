<?php
/**
 * Lightweight source contract for the ClassRemap compatibility feature.
 *
 * Usage: php catalog/bin/verify-class-remap.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$requiredFiles = [
    'class-remap.php',
    'migrations/202609260001_class_remaps.php',
    'src/Infrastructure/Games/CatalogClassRemapAdminService.php',
    'src/Infrastructure/Persistence/PdoClassRemapRepository.php',
    'src/Infrastructure/Persistence/PdoDependencyResolver.php',
    'src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php',
];
foreach ($requiredFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        $failures[] = 'missing file: catalog/' . $relative;
    }
}

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $content = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($content)) {
        $failures[] = 'cannot read: catalog/' . $relative;
        return '';
    }
    return $content;
};

$navigation = $read('lib/CatalogNavigation.php');
if (!str_contains($navigation, "'ClassRemap' => \$root . 'class-remap.php'")) {
    $failures[] = 'Catalog navigation does not expose ClassRemap';
}

$page = $read('class-remap.php');
if (!str_contains($page, "catalog_require_admin_page('ClassRemap')")) {
    $failures[] = 'ClassRemap page is not protected by admin authorization';
}
if (!str_contains($page, "catalog_check_csrf('class-remap')")) {
    $failures[] = 'ClassRemap mutations are not CSRF protected';
}

$repository = $read('src/Infrastructure/Persistence/PdoClassRemapRepository.php');
foreach (['ue_class_remaps', 'mapForGame', 'OldName=NewName', 'Duplicate ClassRemap source name'] as $needle) {
    if (!str_contains($repository, $needle)) {
        $failures[] = 'ClassRemap repository missing contract: ' . $needle;
    }
}

$resolver = $read('src/Infrastructure/Persistence/PdoDependencyResolver.php');
if (!str_contains($resolver, 'mapForGame($gameId)')) {
    $failures[] = 'dependency resolver does not load game-scoped ClassRemaps';
}
if (!str_contains($resolver, '$classRemaps')) {
    $failures[] = 'dependency resolver does not pass ClassRemaps to legacy VerifyImport';
}

$legacy = $read('src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php');
$exactPosition = strpos($legacy, '$matched = self::findCandidate(');
$remapPosition = strpos($legacy, '$remappedObjectName = self::remappedObjectName(', $exactPosition === false ? 0 : $exactPosition);
if ($exactPosition === false || $remapPosition === false || $remapPosition <= $exactPosition) {
    $failures[] = 'ClassRemap is not a fallback after exact legacy lookup';
}
foreach ([
    'selected physical provider and public/private policy remain identical',
    'The retry is single-hop',
    'self::identityHash($remappedObjectName, $className, $classPackage)',
    '$parentSourceIndex',
] as $needle) {
    if (!str_contains($legacy, $needle)) {
        $failures[] = 'legacy ClassRemap contract missing: ' . $needle;
    }
}

$migration = $read('migrations/202609260001_class_remaps.php');
foreach (['ue_class_remaps', 'UNIQUE KEY uq_ue_class_remaps_game (game_id)', 'ON DELETE CASCADE'] as $needle) {
    if (!str_contains($migration, $needle)) {
        $failures[] = 'ClassRemap migration missing contract: ' . $needle;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "ClassRemap verification failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "ClassRemap verification passed.\n");
