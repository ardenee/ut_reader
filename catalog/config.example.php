<?php
declare(strict_types=1);

// Copy this file to config.php and edit the database settings.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'ut_reader_catalog',
        'username' => 'ut_reader',
        'password' => 'change_me',
        'charset' => 'utf8mb4',
    ],
    'site_name' => 'Unreal File Catalog',
    'storage_path' => __DIR__ . '/storage',
    'max_upload_bytes' => 256 * 1024 * 1024,
    'auth' => [
        // How long the "Keep me logged in" token remains valid.
        'remember_days' => 30,
    ],
    'queue' => [
        // Current deployment: MySQL-backed durable jobs run by catalog/bin/catalog-worker.php.
        'name' => 'catalog',
        'lease_seconds' => 120,
    ],
    'cache' => [
        // File cache works on one shared filesystem. Bind CacheStore to Redis
        // before running multiple independent web nodes.
        'driver' => 'file',
        'path' => __DIR__ . '/storage/cache',
        // Keep zero until a page explicitly opts into bounded staleness.
        'dashboard_ttl_seconds' => 0,
    ],
    'pak' => [
        // Built-in PHP PAK extractor limits. No external UnrealPak.exe is required.
        'max_extracted_files' => 20000,
        'max_extracted_bytes' => 8 * 1024 * 1024 * 1024,
    ],
    'allowed_extensions' => ['u','unr','utx','umx','uax','ut2','ut3','upk','uasset','umap'],
    'common_packages' => ['Core','Engine','Editor','Fire','IpDrv','UWindow','Botpack','UnrealShare','UnrealI','Gameplay','UnrealEd'],
    'engine_readers' => [
        'UE1' => ['reader' => '../UE1/UnrealPackageReader.php', 'label' => 'UE1 / Unreal Tournament'],
        'UE2' => ['reader' => '../UE2/UnrealPackageReader.php', 'label' => 'UE2 / UE2.5'],
        'UE3' => ['reader' => '../UE3/UnrealPackageReader.php', 'label' => 'UE3 / UT3'],
        'UE4' => ['reader' => '../UE4/UnrealPackageReader.php', 'label' => 'UE4'],
    ],
];
