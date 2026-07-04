# UnrealDB catalog clean architecture

## Goal

The catalog is being refactored incrementally. Public URLs, HTML, JSON payloads, profile rules, reader behaviour, database schema meaning, and storage paths remain stable. The new structure separates responsibilities while `catalog/lib` remains a compatibility facade for existing pages.

## Active folder structure

```text
catalog/
├── bootstrap.php                         # shared application bootstrap and PSR-4-style autoloader
├── src/
│   ├── Application/                      # use cases; no HTTP rendering or filesystem details
│   │   ├── Catalog/
│   │   │   └── CatalogGameFileListService.php
│   │   ├── Dashboard/
│   │   │   └── CatalogDashboardStats.php
│   │   ├── Dependency/
│   │   │   ├── CatalogDependencyResolver.php
│   │   │   └── CatalogAffectedDependencyRefreshService.php
│   │   ├── Search/
│   │   │   └── CatalogSearchService.php
│   │   └── Upload/
│   │       ├── Contract/CatalogPackageImporter.php
│   │       └── ProfiledUploadService.php
│   ├── Infrastructure/                   # framework, reader, persistence, and storage adapters
│   │   ├── Legacy/LegacyCatalogPackageImporter.php
│   │   └── Readers/CatalogReaderResolver.php
│   └── Presentation/
│       └── Http/CatalogApplication.php   # request-level config, PDO, and session context
├── lib/                                  # temporary compatibility shims and legacy procedural code
├── parsers/                              # existing engine/parser implementations
├── federation/                           # existing federation page controllers
└── *.php                                 # existing page controllers, migrated incrementally
```

## Dependency direction

```text
Presentation controllers
        ↓
Application use cases and contracts
        ↓
Infrastructure adapters
        ↓
Legacy readers / PDO helpers / filesystem / MySQL
```

Application services must not render HTML, inspect `$_GET`/`$_POST`, manage sessions, call `header()`, or know storage paths. Controllers parse HTTP input and render responses. Infrastructure owns the procedural reader bridge, PDO query helpers, filesystem progress files, and future queue adapters.

## Compatibility strategy

Every migrated service keeps its original `catalog/lib/<Service>.php` filename. The file now loads the namespaced implementation and exposes the original global class with `class_alias`.

This allows existing code such as:

```php
CatalogSearchService::findFiles($db, $query);
CatalogDependencyResolver::resolve($db, $gameId, $fileId, $imports);
```

to continue working while all new work imports namespaced classes:

```php
use UnrealDb\Catalog\Application\Search\CatalogSearchService;
use UnrealDb\Catalog\Application\Upload\ProfiledUploadService;
```

No big-bang rename is required, and individual pages can migrate independently.

## Controller migration rule

Each existing page should converge on this shape:

1. Load `catalog/bootstrap.php` and obtain `CatalogApplication`.
2. Parse and validate HTTP input only.
3. Call one application use case.
4. Render the existing HTML or JSON response.

A page controller must not own package reader selection, dependency-resolution precedence, database aggregation algorithms, upload batch orchestration, or filesystem storage rules.

## Existing service ownership

| Module | Owns | Does not own |
|---|---|---|
| `Application/Search` | bounded search staging, search failure boundary | page HTML, PDO setup |
| `Application/Dependency` | dependency match and invalidation rules | parsing packages, progress persistence |
| `Application/Catalog` | page-ID-first list and summary algorithm | URL/filter parsing, table HTML |
| `Application/Dashboard` | live dashboard metric composition | labels and cards |
| `Application/Upload` | upload batch workflow, outcome mapping, failure semantics | reader globals, temp filesystem layout |
| `Infrastructure/Legacy` | bridge to current `CatalogScanner` functions | request orchestration |
| `Infrastructure/Readers` | configured engine-to-reader resolution | profile HTTP forms |
| `Presentation/Http` | session/config/PDO context | domain/use-case behaviour |

## Next safe migrations

1. Convert `profiled-upload.php` into a thin controller that delegates to `ProfiledUploadService` and `LegacyCatalogPackageImporter`.
2. Extract file repositories from `CatalogSupport` so application classes receive typed repositories rather than global helper functions.
3. Move package ingestion orchestration from `CatalogScanner.php` into `Application/Ingestion`, retaining scanner functions as compatibility adapters.
4. Move `UploadProgress.php` behind an infrastructure progress-store contract.
5. Introduce database migration tracking and test fixtures before converting remaining federation pages.
6. Add integration tests around upload outcomes, dependency resolution, search result IDs, and legacy reader selection before changing reader class namespaces.

## Rules for new code

- Put new product behaviour in `catalog/src`, never in `catalog/lib`.
- `catalog/lib` may only contain compatibility shims or untouched legacy code awaiting migration.
- New classes use the `UnrealDb\Catalog` namespace.
- New application services receive collaborators through constructors or contracts.
- Do not call `$_SESSION`, `$_FILES`, `header()`, `echo`, or `exit` from application or infrastructure code.
- Preserve existing page response structures until fixture tests cover a deliberate API change.
