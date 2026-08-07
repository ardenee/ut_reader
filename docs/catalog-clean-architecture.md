# UnrealDB catalog clean architecture

## Goal

The catalog is being refactored incrementally while preserving current behaviour. Public URLs, HTML, JSON payloads, profile rules, reader behaviour, database schema meaning, storage paths, job types, queue semantics and file identity rules remain stable while responsibilities move to clearer boundaries.

`catalog/lib` is now treated as a compatibility surface rather than a permanent implementation layer. When every caller of a compatibility facade has moved to the current namespaced implementation, the facade should be deleted rather than retained as backup code.

## Active folder structure

```text
catalog/
├── bootstrap.php
├── src/
│   ├── Domain/                           # stable domain concepts such as job types/resource policies
│   ├── Application/                      # use-case policy/orchestration that does not own persistence
│   │   ├── Dashboard/
│   │   ├── Jobs/
│   │   ├── Search/
│   │   └── Upload/
│   ├── Infrastructure/
│   │   ├── Import/                       # package/upload implementations
│   │   ├── Jobs/                         # durable job handlers, worker/process coordination
│   │   ├── Metadata/                     # compact metadata read/write/rebuild implementations
│   │   ├── Persistence/                  # PDO query/repository implementations
│   │   │   ├── PdoCatalogDependencyRebuilder.php
│   │   │   ├── PdoDependencyResolver.php
│   │   │   ├── PdoDependencyReadSource.php
│   │   │   ├── PdoGameFileListQuery.php
│   │   │   └── PdoPackageTablePageQuery.php
│   │   └── Readers/                      # configured engine-to-reader resolution
│   └── Presentation/
│       └── Http/
├── lib/
│   ├── CatalogScanner.php                # thin scanner compatibility include manifest
│   └── Scanner/
│       ├── CatalogScannerPath.php         # package/source path compatibility functions
│       ├── CatalogScannerSupport.php      # reader/progress/storage helpers
│       ├── CatalogScannerDependencies.php # thin delegates to PDO dependency rebuilder
│       └── CatalogScannerImport.php       # current scanner import workflow
├── parsers/
├── federation/
└── *.php                                 # existing page controllers, migrated incrementally
```

## Dependency direction

```text
Presentation / entry points
        ↓
Application use cases and contracts
        ↓
Infrastructure adapters
        ↓
PDO / filesystem / readers / worker processes / MySQL
```

Application code must not render HTML, inspect request globals, manage sessions, launch processes, or own SQL/filesystem persistence. Infrastructure owns database queries, filesystem storage, reader bridges and worker/process implementations.

Some older Application classes still violate this direction. They are migration targets, not patterns for new code.

## Scanner and dependency ownership

The old `CatalogScanner.php` monolith has been decomposed without changing the public `scanner_*` API used by existing jobs and pages.

Dependency persistence no longer lives in the scanner procedural file:

- `PdoDependencyResolver` owns exact package/provider/object lookup semantics.
- `PdoCatalogDependencyRebuilder` owns single-file, whole-game and affected dependency rebuild persistence.
- `CatalogAffectedDependencyRefreshCoordinator` owns durable affected-refresh discovery/queue coordination.
- `CompactDependencyRebuilder` uses the same Infrastructure resolver for compact metadata.
- `CatalogScannerDependencies.php` only preserves the existing global function signatures while callers are migrated.

The former `Application/Dependency/CatalogDependencyResolver.php` and `CatalogAffectedDependencyRefreshService.php` compatibility implementations have been removed.

## Compatibility strategy

Compatibility exists to keep current routes and callers stable during a migration; it is not a reason to retain obsolete implementations.

Use these rules:

1. Keep an existing public/global function when active callers still require that exact contract.
2. Move its real implementation to the correct namespaced layer.
3. Make the compatibility function a thin delegate.
4. Migrate callers to the namespaced implementation where practical.
5. Delete the facade once no live caller requires it.

Do not keep parallel old/new implementations as fallback code when the current implementation is authoritative.

## Controller migration rule

Each existing page should converge on this shape:

1. Load the shared bootstrap/application context.
2. Parse and validate HTTP input.
3. Construct or resolve the required use-case/query collaborator.
4. Invoke it.
5. Render the existing HTML or JSON response.

A page controller should not own package reader selection, dependency-resolution precedence, database aggregation algorithms, upload batch orchestration, worker lifecycle or filesystem storage rules.

## Current ownership examples

| Module | Owns | Does not own |
|---|---|---|
| `Application/Search` | search use-case policy and outcome handling | page HTML, PDO setup |
| `Application/Upload` | upload use-case contract/orchestration | reader globals, durable storage implementation |
| `Infrastructure/Persistence` | PDO queries, keyset paging, dependency/provider persistence | HTTP parsing/rendering |
| `Infrastructure/Metadata` | compact metadata loading/writing/rebuilding | page controllers |
| `Infrastructure/Jobs` | durable handlers, affected-refresh coordination, worker/process implementation | HTTP UI |
| `Infrastructure/Readers` | configured engine-to-reader resolution | profile forms |
| `lib/Scanner` | temporary global scanner compatibility API | new product/business behaviour |
| `Presentation/Http` | request-level config/PDO/session composition | persistence algorithms |

## Next safe migrations

1. Move the implementation of `scanner_scan_uploaded_file()` from `lib/Scanner/CatalogScannerImport.php` into a namespaced import service and leave only a global delegate while legacy callers remain.
2. Move scanner package/source-path policy into a namespaced `PackageNamePolicy`/`SourcePathPolicy`, then migrate direct global callers.
3. Retire remaining dependency/schema compatibility functions when their callers use `PdoDependencySchemaManager` directly.
4. Decompose `CatalogUnverifiedIndex.php` into staging/index/query services.
5. Decompose `CatalogDetachedWorker.php` into pool, runtime-state and process-launch components while preserving Windows behaviour exactly.
6. Continue moving page-local SQL into intent-specific Infrastructure query objects.

## Validation policy

The project currently uses application/web-interface validation rather than an automated PHP test suite.

For architecture changes:

- run `php -l` on every changed PHP file;
- verify published Git blobs match the locally linted files when large files are replaced;
- exercise the affected web/API workflow manually;
- preserve SQL ordering, result shapes, cursor semantics, job types, dedupe keys, retries and progress reporting unless a separate behavioural change is intentional;
- do not add GitHub Actions/test workflows unless explicitly requested.

## Rules for new code

- Put new product behaviour in `catalog/src`, not `catalog/lib`.
- `catalog/lib` may contain compatibility delegates or legacy code awaiting migration, but not a second implementation of already-migrated behaviour.
- New classes use the `UnrealDb\Catalog` namespace.
- Infrastructure owns PDO/filesystem/process/reader implementation details.
- Application owns use-case policy and contracts, not Infrastructure construction.
- Do not call `$_SESSION`, `$_FILES`, `header()`, `echo`, or `exit` from Application services.
- Request globals may remain temporarily at a legacy compatibility boundary, but should not leak into new Infrastructure services.
- Preserve existing response structures and operational semantics during architecture-only refactors.
