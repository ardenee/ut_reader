#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;
use UnrealDb\Catalog\Infrastructure\Persistence\SearchDocumentMigrationExecutor;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

function search_index_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php catalog/bin/search-document-indexes.php status\n");
    fwrite(STDOUT, "  php catalog/bin/search-document-indexes.php build [--allow-system-temp]\n");
}

/** @return array{command:string,allow_system_temp:bool} */
function search_index_arguments(array $arguments): array
{
    $command = 'status';
    $commandSet = false;
    $allowSystemTemp = false;
    foreach ($arguments as $argument) {
        $argument = (string)$argument;
        if ($argument === '--allow-system-temp') {
            $allowSystemTemp = true;
            continue;
        }
        if (str_starts_with($argument, '-')) {
            throw new InvalidArgumentException('Unknown option: ' . $argument);
        }
        if ($commandSet) {
            throw new InvalidArgumentException('Unexpected argument: ' . $argument);
        }
        $command = strtolower(trim($argument));
        $commandSet = true;
    }
    return ['command' => $command, 'allow_system_temp' => $allowSystemTemp];
}

/** @return array{tmpdir:string,innodb_tmpdir:string,datadir:string} */
function search_index_database_paths(PDO $db): array
{
    $row = $db->query(
        'SELECT @@global.tmpdir tmpdir,@@global.innodb_tmpdir innodb_tmpdir,@@global.datadir datadir'
    )->fetch(PDO::FETCH_ASSOC);
    return [
        'tmpdir' => trim((string)($row['tmpdir'] ?? '')),
        'innodb_tmpdir' => trim((string)($row['innodb_tmpdir'] ?? '')),
        'datadir' => trim((string)($row['datadir'] ?? '')),
    ];
}

function search_index_drive(string $path): string
{
    if (preg_match('/^([A-Za-z]):[\\\/]/', trim($path), $match) === 1) {
        return strtoupper($match[1]) . ':';
    }
    return '';
}

function search_index_print_paths(array $paths): void
{
    fwrite(STDOUT, "MySQL/MariaDB paths:\n");
    fwrite(STDOUT, '  tmpdir:        ' . ($paths['tmpdir'] !== '' ? $paths['tmpdir'] : '(server default)') . "\n");
    fwrite(STDOUT, '  innodb_tmpdir: ' . ($paths['innodb_tmpdir'] !== '' ? $paths['innodb_tmpdir'] : '(uses tmpdir)') . "\n");
    fwrite(STDOUT, '  datadir:       ' . ($paths['datadir'] !== '' ? $paths['datadir'] : '(unknown)') . " (database remains here)\n");
}

try {
    $arguments = search_index_arguments(array_slice($argv, 1));
    if (in_array($arguments['command'], ['help', '--help', '-h'], true)) {
        search_index_usage();
        exit(0);
    }
    if (!in_array($arguments['command'], ['status', 'build'], true)) {
        throw new InvalidArgumentException('Unknown command: ' . $arguments['command']);
    }

    putenv('UNREALDB_DEFER_INDEXES');
    $db = catalog_db(catalog_config());
    $schema = new SchemaInspector($db);
    if (!$schema->tableExists('ue_search_documents')) {
        throw new RuntimeException('ue_search_documents does not exist. Apply migration 202607270003 first.');
    }

    $paths = search_index_database_paths($db);
    search_index_print_paths($paths);
    $rows = (int)$db->query('SELECT COUNT(*) FROM ue_search_documents')->fetchColumn();
    fwrite(STDOUT, 'Search document rows: ' . $rows . "\n");

    $definitions = SearchDocumentMigrationExecutor::indexDefinitions();
    foreach ($definitions as [$index]) {
        fwrite(STDOUT, '  ' . str_pad($index, 36) . ($schema->indexExists('ue_search_documents', $index) ? 'present' : 'missing') . "\n");
    }

    if ($arguments['command'] === 'status') {
        exit(0);
    }

    $systemDrive = strtoupper(trim((string)(getenv('SystemDrive') ?: 'C:')));
    $tmpDrive = search_index_drive($paths['tmpdir']);
    $innodbDrive = search_index_drive($paths['innodb_tmpdir']);
    $unsafeTmp = $tmpDrive !== '' && $tmpDrive === $systemDrive;
    $unsafeInnodb = $paths['innodb_tmpdir'] !== '' && $innodbDrive !== '' && $innodbDrive === $systemDrive;
    if (($unsafeTmp || $unsafeInnodb) && !$arguments['allow_system_temp']) {
        throw new RuntimeException(
            'Refusing to build large search indexes while MySQL temporary work points to the Windows system drive. '
            . 'Keep the database datadir where it is, but move tmpdir and innodb_tmpdir to a drive with adequate temporary free space, restart MySQL, confirm with the status command, then retry. '
            . 'Use --allow-system-temp only when you have deliberately confirmed sufficient system-drive space.'
        );
    }

    foreach ($definitions as [$index, $sql]) {
        if ($schema->indexExists('ue_search_documents', $index)) {
            continue;
        }
        fwrite(STDOUT, 'Building ' . $index . "...\n");
        $db->exec($sql);
        fwrite(STDOUT, 'Built ' . $index . ".\n");
    }
    fwrite(STDOUT, "All search-document indexes are present.\n");
    exit(0);
} catch (Throwable $error) {
    error_log('[UnrealDB search indexes] ' . get_class($error) . ': ' . $error->getMessage());
    fwrite(STDERR, 'Search index command failed: ' . $error->getMessage() . "\n");
    search_index_usage();
    exit(1);
}
