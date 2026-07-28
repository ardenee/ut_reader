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
    // Ordinary package upload limit. PAK containers have a separate, much
    // larger limit and are transferred by the browser in resumable chunks.
    'max_upload_bytes' => 256 * 1024 * 1024,
    'max_container_upload_bytes' => 64 * 1024 * 1024 * 1024,
    'chunk_upload' => [
        // Each HTTP request carries only one chunk, avoiding PHP/Apache limits
        // on a single multi-gigabyte request. 16 MiB is safe for typical hosts.
        'chunk_bytes' => 16 * 1024 * 1024,
        // Incomplete/resumable chunk stores older than this may be pruned.
        'stale_hours' => 168,
    ],
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
        // Detached inventory workers raise lower PHP CLI limits to this value.
        // Streaming readers avoid package-sized copies, but large object tables
        // still need headroom. Existing higher or unlimited limits are preserved.
        'worker_memory_limit' => '512M',
        // Usually auto-detected. Set an absolute CLI PHP path when the web PHP
        // binary differs, for example 'D:/PHP/php.exe' or '/usr/local/bin/php82'.
        'worker_php_binary' => '',
    ],
    'game_backups' => [
        // Full independent file-copy exports for FTP/SFTP transfer and restore.
        // Keep this outside the public web root when possible. Leaving it empty
        // uses <storage_path>/game-backups.
        'path' => '',
    ],
    'cache' => [
        // File cache works on one shared filesystem. Bind CacheStore to Redis
        // before running multiple independent web nodes.
        'driver' => 'file',
        'path' => __DIR__ . '/storage/cache',
        // Keep zero until a page explicitly opts into bounded staleness.
        'dashboard_ttl_seconds' => 0,
        // Anonymous GET pages in the explicit public allow-list use this small
        // shared response cache. Logged-in, remembered and POST requests bypass it.
        'public_response_enabled' => true,
        'public_response_stale_seconds' => 300,
        'public_response_max_bytes' => 8 * 1024 * 1024,
        'public_route_ttl_seconds' => [
            'games.php' => 120,
            'library.php' => 120,
            'game-page.php' => 120,
            'game-files.php' => 60,
            'file-info.php' => 300,
            'file-examine.php' => 300,
            'game-paks.php' => 120,
            'game-upks.php' => 120,
            'pak-info.php' => 300,
            'upk-info.php' => 300,
        ],
    ],
    // Targets shown by Maintenance -> Workload Tracing. These defaults are for
    // the current 64 GB Windows host running Apache, PHP and MySQL together.
    'performance' => [
        'host_memory_gb' => 64,
        'mysql_buffer_pool_bytes' => 36 * 1024 * 1024 * 1024,
        'mysql_max_connections' => 120,
        'mysql_thread_cache_size' => 50,
        'apache_threads_per_child' => 100,
        'opcache_memory_mb' => 256,
        'opcache_max_accelerated_files' => 32531,
    ],
    'pak' => [
        // Admin-only full-container extraction limits for large UE4/UE5 PAKs.
        'max_extracted_files' => 250000,
        'max_extracted_bytes' => 128 * 1024 * 1024 * 1024,
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
