<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Application\PackageAlias\PackageAliasRepository;
use UnrealDb\Catalog\Application\Upload\ProfiledUploadService;
use UnrealDb\Catalog\Application\Upload\UploadErrorFormatter;
use UnrealDb\Catalog\Application\Upload\UploadResult;
use UnrealDb\Catalog\Infrastructure\Composition\CatalogServiceFactory;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageAliasRepository;
use UnrealDb\Catalog\Presentation\Http\LegacySupportHooks;

function architecture_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$result = UploadResult::create('duplicate', 'Example.utx', 'Duplicate in selected game', [
    'file_size_text' => '10 KB',
    'package_guid' => 'A-B-C-D',
    'duplicate_original_name' => 'Original.utx',
    'duplicate_guid' => 'internal',
    'duplicate_file_size_text' => 'internal',
]);

architecture_expect(!array_key_exists('duplicate_guid', $result), 'Internal duplicate GUID leaked into the public result.');
architecture_expect(!array_key_exists('duplicate_file_size_text', $result), 'Internal duplicate size leaked into the public result.');
architecture_expect(
    UploadResult::text($result) === 'Example.utx: duplicate - Duplicate in selected game | size: 10 KB | GUID: A-B-C-D | copy of: Original.utx',
    'Upload result text changed.'
);
architecture_expect(
    ProfiledUploadService::resultText($result) === UploadResult::text($result),
    'Profiled upload compatibility formatter diverged.'
);

architecture_expect(
    UploadErrorFormatter::concise(new RuntimeException('Bad package tag 0x1234ABCD at byte 4')) === 'Bad package tag 0x1234ABCD',
    'Package-tag error formatting changed.'
);
architecture_expect(
    UploadErrorFormatter::concise(new RuntimeException('RuntimeException: Broken package File: x Trace: y')) === 'Broken package',
    'Generic upload error formatting changed.'
);

architecture_expect(class_exists(LegacySupportHooks::class), 'Presentation compatibility hooks are not autoloadable.');
architecture_expect(class_exists(CatalogServiceFactory::class), 'Service composition root is not autoloadable.');
architecture_expect(interface_exists(PackageAliasRepository::class), 'Package alias application port is not autoloadable.');
architecture_expect(class_exists(PdoPackageAliasRepository::class), 'Package alias PDO adapter is not autoloadable.');

$supportFile = file_get_contents(__DIR__ . '/../lib/CatalogSupport.php');
architecture_expect(is_string($supportFile), 'CatalogSupport.php could not be read.');
architecture_expect(!str_contains($supportFile, 'ob_start'), 'CatalogSupport.php regained rendering logic.');
architecture_expect(!str_contains($supportFile, 'SELECT scan_status'), 'CatalogSupport.php regained database routing logic.');
architecture_expect(
    str_contains($supportFile, 'LegacySupportHooks::register'),
    'CatalogSupport.php no longer delegates legacy presentation hooks.'
);

$aliasFacade = file_get_contents(__DIR__ . '/../lib/CatalogPackageAliases.php');
architecture_expect(is_string($aliasFacade), 'CatalogPackageAliases.php could not be read.');
architecture_expect(!str_contains($aliasFacade, 'CREATE TABLE'), 'Package alias facade regained schema ownership.');
architecture_expect(!str_contains($aliasFacade, 'INSERT INTO'), 'Package alias facade regained persistence ownership.');
architecture_expect(
    str_contains($aliasFacade, 'PdoPackageAliasRepository'),
    'Package alias facade no longer delegates to its repository.'
);

echo "Architecture boundary tests passed.\n";
