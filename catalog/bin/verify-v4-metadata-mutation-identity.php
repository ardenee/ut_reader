#!/usr/bin/env php
<?php
/**
 * Regression contract for verified UEDB5 package identity republishing.
 * The verified source is reparsed and V5 projections/dependencies are rebuilt.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$mutationPath = $root . '/src/Infrastructure/Metadata/CatalogCompactMetadataMutationService.php';
$publisherPath = $root . '/src/Infrastructure/Metadata/Uedb5VerifiedFilePublisher.php';
$mutation = (string)@file_get_contents($mutationPath);
$publisher = (string)@file_get_contents($publisherPath);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok) use (&$checks, &$failures): void {
    $checks[$name] = $ok;
    if (!$ok) {
        $failures[] = $name;
    }
};

$record('verified_mutation_requires_v5',
    str_contains($mutation, 'Uedb5MetadataContainer::FORMAT_VERSION')
    && str_contains($mutation, 'ue_uedb5_files'));
$record('verified_mutation_uses_v5_publisher',
    str_contains($mutation, 'new Uedb5VerifiedFilePublisher($this->db, $this->config)'));
$record('mutation_has_no_deleted_v4_writer',
    !str_contains($mutation, 'BlockedCompressedMetadata'));
$record('publisher_reparses_authoritative_source',
    str_contains($publisher, 'buildForGameId($gameId, $path, $file)')
    && str_contains($publisher, 'assertSourceIdentity($path, $file)'));
$record('publisher_writes_and_registers_v5',
    str_contains($publisher, '$this->writer->write($snapshot)')
    && str_contains($publisher, '$this->registration->register($gameId, $fileId)'));
$record('publisher_refreshes_projections_and_dependencies',
    str_contains($publisher, '$this->basePublisher->publish($snapshot, $registration)')
    && str_contains($publisher, '$this->dependencies->runFile($gameId, $fileId, true, true)'));

foreach ([$mutationPath, $publisherPath] as $path) {
    try {
        token_get_all((string)file_get_contents($path), TOKEN_PARSE);
        $record('php_syntax_' . basename($path), true);
    } catch (ParseError $error) {
        $record('php_syntax_' . basename($path), false);
    }
}
echo json_encode(['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 2);
