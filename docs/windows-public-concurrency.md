# Windows public concurrency and workload tracing

This deployment profile targets one 64 GB Windows host running Apache, PHP and MySQL together. The database remains on its normal C: storage. L: is not used by normal application or database operation; it remains available only for unusually large migration temporary work when explicitly required.

## Application protections

- Anonymous read-only requests do not create a PHP session or acquire a session-file lock.
- Explicitly approved public GET pages use a short shared file response cache.
- Logged-in, remembered, POST, CSRF and download responses bypass the shared cache.
- Public broad search is game-scoped, returns at most 100 displayed files and does not calculate an exact total.
- The public home page reads `ue_game_catalog_stats` instead of aggregating `ue_files` for every visitor.
- Request tracing samples 1 in 20 normal requests and always records slow, CPU-heavy or high-memory requests.
- Maintenance -> Workload Tracing shows route wall/SQL/CPU/peak-memory aggregates and MySQL Performance Schema statement digests.

## Recommended 64 GB targets

### MySQL `my.ini`

```ini
[mysqld]
innodb_buffer_pool_size=36G
innodb_buffer_pool_dump_at_shutdown=ON
innodb_buffer_pool_load_at_startup=ON
innodb_buffer_pool_dump_pct=50
max_connections=120
thread_cache_size=50
slow_query_log=ON
long_query_time=0.5
min_examined_row_limit=1000
performance_schema=ON
```

Do not raise global `sort_buffer_size`, `join_buffer_size`, `read_buffer_size`, `read_rnd_buffer_size`, `tmp_table_size` or `max_heap_table_size` aggressively. Several of these can multiply by active connections.

### Apache `httpd.conf`

```apache
<IfModule mpm_winnt_module>
    ThreadsPerChild 100
    MaxConnectionsPerChild 0
</IfModule>
```

### PHP `php.ini`

```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=32531
opcache.validate_timestamps=1
opcache.revalidate_freq=30
```

When deployments always restart Apache, `opcache.validate_timestamps=0` can remove timestamp checks, but every code deployment must then restart Apache before the new code is used.

## Deployment

Stop the detached worker, pull the update, apply migration `202607280001`, verify migrations, restart Apache/MySQL after their configuration changes, then restart the worker.

```powershell
git pull
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate --lock-timeout=60
php catalog/bin/migrate.php verify
```

After normal use, open **Maintenance -> Workload Tracing**. Prioritize routes with high total CPU, average SQL time, maximum peak RAM or repeated slow samples. For SQL, prioritize statement digests with high total time, rows examined, disk temporary tables or no-index counts.
