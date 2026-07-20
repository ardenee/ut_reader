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
        // Persistent rotating remember-me token lifetime.
        'remember_days' => 30,
        // Shared-storage login throttling. Client IP is used when available.
        'login_max_attempts' => 8,
        'login_window_seconds' => 15 * 60,
        'login_block_seconds' => 15 * 60,
    ],
    'queue' => [
        // MySQL-backed durable jobs. Web uploads automatically launch a detached
        // CLI worker which drains the available queue and exits.
        'name' => 'catalog',
        'lease_seconds' => 120,
        // Usually auto-detected. Set an absolute CLI PHP path when the web PHP
        // binary differs, for example 'D:/PHP/php.exe' or '/usr/local/bin/php82'.
        'worker_php_binary' => '',
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
    'ue4' => [
        // Base parser profile for standard UE4 packages. This is the default for
        // all UE4 games until a game-specific profile below overrides a known
        // package-summary/property-layout difference.
        'parser_profile' => [
            'profile_key' => 'standard-ue4',
            'label' => 'Standard UE4 package parser',
            // Parser assumption for unversioned UE4 packages; not a version read
            // from the package file itself.
            'assumed_unversioned_parser_version' => 511,
            'source_reference' => 'UE4 package summary / FLinkerLoad behaviour',
        ],
        // Per-game overlays are keyed by ue_games.slug or a cleaned profile name.
        // Add only the differences here when another UE4 game is investigated.
        'parser_profiles' => [
            'ut4-alpha' => [
                'profile_key' => 'ut4-alpha',
                'label' => 'Unreal Tournament 4 Alpha UE4 parser',
                'assumed_unversioned_parser_version' => 511,
                'source_reference' => 'ardenee/UnrealTournament clean-master',
            ],
        ],
        // Backwards-compatible shorthand. Prefer parser_profiles above for new games.
        'assumed_unversioned_parser_versions' => [
            // 'another-ue4-game-slug' => 511,
        ],
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
