<?php
declare(strict_types=1);

// Copy this file to config.php and edit the database settings.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'ut_reader_catalog',
        'username' => 'root',
        'password' => '71MM317pplp1019=',
        'charset' => 'utf8mb4',
    ],
    'site_name' => 'UnrealDB',
    'storage_path' => __DIR__ . '/storage',
    'max_upload_bytes' => 256 * 1024 * 1024,
    'allowed_extensions' => ['u','unr','utx','umx','uax','ut2','ut3','upk','uasset','umap'],
    'common_packages' => ['Core','Engine','Editor','Fire','IpDrv','UWindow','Botpack','UnrealShare','UnrealI','Gameplay','UnrealEd'],
    'engine_readers' => [
        'UE1' => ['reader' => '../UE1/UnrealPackageReader.php', 'label' => 'UE1 / Unreal Tournament'],
        'UE2' => ['reader' => '../UE2/UnrealPackageReader.php', 'label' => 'UE2 / UE2.5'],
        'UE3' => ['reader' => '../UE3/UnrealPackageReader.php', 'label' => 'UE3 / UT3'],
        'UE4' => ['reader' => '../UE4/UnrealPackageReader.php', 'label' => 'UE4'],
    ],
];
