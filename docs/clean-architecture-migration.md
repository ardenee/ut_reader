# UnrealDB clean architecture migration

## Goal

Evolve the catalog into a modular monolith without changing package parsing,
profile compatibility, storage layout, public URLs, result arrays, progress
messages, dependency rules, or federation behaviour.

The migration is incremental. Existing procedural entry points remain compatible
while new work is placed behind explicit application and infrastructure ports.

## Folder structure

```text
catalog/
  bootstrap/
    autoload.php                 Namespaced-code loading boundary

  src/
    Domain/
      Jobs/                      Durable domain values and identifiers

    Application/
      Catalog/                   Catalog listing use cases
      Dependency/                Exact dependency-resolution use cases
      Jobs/                      Queue and worker contracts
      PackageAlias/              Logical package-alias persistence port
      Search/                    Search use cases
      Unverified/
        Contract/                Queue inventory, record, and filesystem ports
        UnverifiedDuplicateCleanupService.php
      Upload/
        Contract/                Importer and diagnostic ports
        ProfiledUploadService.php
        UploadResult.php
        UploadErrorFormatter.php

    Infrastructure/
      Cache/                     Cache adapters
      Composition/               Service factories / dependency wiring
      Filesystem/                Native filesystem adapters
      Legacy/                    Procedural scanner compatibility adapters
      Logging/                   Operational logging adapters
      Persistence/               PDO repositories, schema managers, job queue
      Readers/                   UE reader selection adapters
      Storage/                   Package/object-storage adapters
      Unverified/                Legacy unverified queue inventory adapter

    Presentation/
      Http/                      HTTP bootstrap and compatibility hooks

  lib/                           Temporary legacy compatibility layer
  api/                           HTTP API entry points
  bin/                           CLI worker entry points
  tests/                         Contract and integration tests
```

## Dependency rule

Dependencies point inward:

```text
Presentation -> Application -> Domain
Infrastructure -> Application/Domain contracts
Legacy lib functions -> namespaced compatibility adapters
```

Application classes must not depend on sessions, request globals, HTML, header
emission, concrete package readers, PDO, or physical storage paths.
Infrastructure implements those details. Presentation code validates HTTP input
and renders the existing response contract.

## Implemented boundaries

### Bootstrap

`catalog/bootstrap/autoload.php` provides a small PSR-4-style loader without
adding a Composer runtime requirement. Shared-host compatibility is retained.

### Presentation compatibility hooks

The script-specific asset injection and staged-file redirect previously lived in
`CatalogSupport.php`. They now live in
`Presentation/Http/LegacySupportHooks.php`. `CatalogSupport.php` is reduced to a
bootstrap/compatibility entry point.

### Upload result contract

`Application/Upload/UploadResult.php` owns the established associative result
shape and flash/progress text. Internal duplicate metadata remains filtered as
before.

`Application/Upload/UploadErrorFormatter.php` owns the established concise error
text, including package-tag errors.

### Upload ports and adapters

`CatalogPackageImporter` remains the application port for scanning/importing a
package. `LegacyCatalogPackageImporter` is the compatibility adapter for the
current procedural scanner.

`UploadFailureLogger` is the application diagnostic port.
`LegacyUploadFailureLogger` bridges it to PHP error logging and the optional
federation application log.

`CatalogServiceFactory` is the composition root that wires application services
to concrete infrastructure.

### Package alias persistence

`PackageAliasRepository` owns the application persistence contract.
`PdoPackageAliasRepository` owns alias SQL and the temporary runtime schema
compatibility behaviour. Existing `catalog_package_alias_*` functions are now
thin wrappers, so scanner callers remain unchanged.

### Dependency schema management

`PdoDependencySchemaManager` owns dependency metadata and asset-registry schema
inspection/upgrade operations. Existing `catalog_dependency_schema_*` functions
remain compatibility wrappers and no longer contain DDL.

### Unverified duplicate cleanup

`UnverifiedDuplicateCleanupService` owns grouping, keeper selection, revalidation,
delete ordering, and result counters.

Its dependencies are ports:

- `UnverifiedQueueInventory` supplies physical queue entries;
- `UnverifiedRecordStore` owns database cleanup;
- `UnverifiedFileSystem` owns hashing, stat, and deletion operations.

The current adapters preserve the established queue layout, PDO records, and
native filesystem behaviour. `CatalogUnverifiedDuplicates.php` remains the
public compatibility facade used by the existing JSON endpoint.

## Compatibility policy

During migration:

1. Existing PHP URLs remain entry points.
2. Existing global functions may remain as thin wrappers.
3. One canonical namespaced implementation owns each migrated rule.
4. Result keys, status labels, message text, redirects, and HTTP status codes are
   covered by contract tests before wrappers are removed.
5. Schema changes remain explicit deployment migrations; temporary runtime schema
   compatibility is isolated in infrastructure managers.
6. Parser behaviour is frozen behind fixtures before reader classes are renamed
   or namespaced.

## Next migration sequence

1. Route `profiled-upload.php` through `CatalogServiceFactory` while retaining PAK
   and redirect-archive orchestration as presentation adapters.
2. Extract unverified queue storage and indexing into application use cases; the
   duplicate-cleanup use case is already migrated.
3. Replace global SQL helpers in search, dependency, and listing application
   services with repository interfaces.
4. Consolidate runtime schema mutation into versioned deployment migrations and
   add exact schema contract checks.
5. Split `CatalogScanner.php` into reader, identity, storage, persistence, and
   dependency-refresh collaborators.
6. Namespace UE reader implementations at source and retain temporary class
   aliases for standalone viewer compatibility.
7. Move long-running ingestion and dependency rebuild operations behind the
   existing durable job queue after response/progress contracts are fixture-tested.

## Scaling model

The target remains a modular monolith. Scale-up steps are adapter replacements,
not application rewrites:

- local package storage -> S3-compatible object storage;
- file cache/session state -> Redis;
- MySQL queue -> broker-backed `JobQueue` adapter;
- primary reads -> read replicas for explicitly read-only models;
- substring SQL search -> derived search index.

The relational catalog and exact Unreal package/dependency identity rules remain
the source of truth.
