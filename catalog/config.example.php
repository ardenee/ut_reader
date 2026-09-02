<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the tracked template for UnrealDB runtime configuration.
 * Why: It gives new installations a safe starting point for creating the ignored `catalog/config.php` file.
 * Role: Installation/configuration template; not the live server configuration.
 * Audit: Keep generic and credential-free so it can remain in source control.
 */
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
    // Ordinary package upload limit. PAK and ZIP/7z/RAR containers have a
    // separate larger limit and are transferred by the browser in chunks.
    'max_upload_bytes' => 256 * 1024 * 1024,
    'max_container_upload_bytes' => 64 * 1024 * 1024 * 1024,
    'chunk_upload' => [
        // Each HTTP request carries only one chunk, avoiding web/PHP limits on
        // a single multi-gigabyte request. 16 MiB is safe for typical hosts.
        'chunk_bytes' => 16 * 1024 * 1024,
        // Incomplete/resumable chunk stores older than this may be pruned.
        'stale_hours' => 168,
    ],
    'archive' => [
        // Archive decoding is PHP-extension-only and therefore portable across
        // Windows/Linux web servers without invoking 7z/unrar command-line tools.
        // ZIP prefers ext-zip (ZipArchive). RAR and 7z require ext-archive
        // (cataphract/libarchive); ext-archive also provides a ZIP fallback.
        // Total bytes that one archive-expansion job may unpack. Zero uses a
        // bounded default derived from max_container_upload_bytes.
        'max_unpacked_bytes' => 0,
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
        // Usually auto-detected from the current PHP runtime and PATH.
        // An explicit path is only a preference: if it later disappears after a
        // host/drive change, detached workers fall back to automatic detection.
        'worker_php_binary' => '',
    ],
    'game_backups' => [
        // Full independent file-copy exports for FTP/SFTP transfer and restore.
        // Keep this outside the public web root when possible. Leaving it empty
        // uses <storage_path>/game-backups.
        'path' => '',
    ],
    'cache' => [
        // Shared filesystem directory used by the anonymous public-response cache.
        'path' => __DIR__ . '/storage/cache',
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
    // Targets shown by Maintenance -> Workload Tracing. Tune these defaults for
    // the resources available to the server running the web app and database.
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
    // UE1/UE2 use namespaced streaming readers and UE3 uses the strict catalog
    // parser directly. UE4 remains the only generation backed by a configured
    // external reader path until its root parser is migrated.
    'engine_readers' => [
        'UE4' => ['reader' => '../UE4/UnrealPackageReader.php', 'label' => 'UE4'],
    ],
];
