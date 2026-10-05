# UEDB5 SQL projection contract

Status: normative for UEDB5 SQL design.

This document freezes the database boundary for UEDB5. It exists specifically to prevent the format-5 migration from rebuilding a second source-shaped Unreal package database in MySQL.

The authoritative source-shaped record is the `.uedb5` file. SQL is an accelerator, catalogue relationship store, and materialized-summary layer only.

The machine-readable companion is `catalog/src/Infrastructure/Metadata/Uedb5SqlProjectionContract.php`. `catalog/bin/verify-uedb5-sql-projection-contract.php` prevents the baseline contract from silently growing source-shaped columns.

## Governing rule

For a package/object/dependency question, SQL may answer **where to look**. UEDB5 answers **what the Unreal source record actually says**.

The required pattern is:

```text
SQL indexed key -> candidate file/object index -> UEDB5 source row -> source-specific verification
```

not:

```text
SQL projection -> reconstructed Unreal export/import record -> verification
```

The recent UT3 resolver is the reference model: `ue_export_lookup` discovers candidate export indexes by ObjectName, then `BlockedCompressedMetadataReader::rowsByIndexes()` loads class/outer/flags from UEDB4. V5 generalizes that design using UEDB5 as the authoritative row store.
## Coexistence rule

Until cutover, V5 projections use dedicated `ue_uedb5_*` tables. They must not overwrite the live V4 projection tables while production still reads UEDB4.

```text
production runtime = UEDB4 + current V4 SQL projections
migration/staging  = UEDB5 + ue_uedb5_* projections
```

This lets the V5 catalogue be built, measured, verified and discarded/rebuilt independently before the atomic runtime switch.

The baseline V5 tables are:

- `ue_uedb5_files` — one registration/primary package-identity row per staged V5 file;
- `ue_uedb5_provider_keys` — primary/alias package-provider lookup keys;
- `ue_uedb5_search_keys` — one dictionary row per distinct normalized searchable text;
- `ue_uedb5_name_candidates` — one row per file and distinct normalized FName;
- `ue_uedb5_object_candidates` — one narrow row per ordinary export or cell export;
- `ue_uedb5_dependency_edges` — one compact row per persisted dependency result;
- `ue_uedb5_dependency_packages` — one materialized summary row per requiring file/package.

`ue_uedb5_object_path_candidates` is optional and **disabled by default**. It may be enabled only after a measured query contract proves that object-path lookup needs its own database index.

No baseline V5 projection may depend on `ue_terms`, `ue_export_path_lookup`, `ue_legacy_export_identity_lookup`, or `ue_dependency_identity_lookup`.
## 1. File registration and primary package identity

`ue_uedb5_files` is O(files), not O(objects). It contains only the material needed to locate and verify a V5 container and find primary package providers:

- `file_id`, `game_id`;
- fixed format/codec identity;
- compressed/uncompressed size, block count and whole-container SHA-256;
- `package_family` and `source_policy`;
- package-key kind and package key;
- source-derived package name for the staged V5 identity;
- the manifest section-count map as one JSON value;
- creation/update timestamps.

The complete manifest section-count map is stored once here because V5 package families do not all share the same Name/Import/Export table shape. It is registration metadata, not a per-object projection.

The package key is an accelerator identity:

- classic package families use a normalized package-name key;
- Zen uses the exact `FPackageId` identity represented by its key kind.

The UEDB5 manifest remains authoritative if the projection and file disagree.

## 2. Provider keys

`ue_uedb5_provider_keys` is O(primary files + aliases). It contains only:

- game;
- package-key kind/value;
- provider file ID;
- provider source kind and source ID.

It does not contain exports, class graphs, object coverage, or a synthetic union of multiple physical files. Source-valid provider resolution must establish exactly one physical file before source-specific object verification; if several valid physical files remain and runtime provider order is unavailable, the dependency remains unresolved rather than choosing one by catalogue policy.
## 3. Search-key dictionary

V5 does not use an auto-increment term ID as the identity carried by every projection row.

`ue_uedb5_search_keys` stores each distinct normalized searchable text once. Its contract carries:

- a compact 16-byte lookup hash and original normalized byte length;
- a SHA-256 fingerprint for deterministic dictionary uniqueness/collision separation;
- the normalized text once for prefix/contains catalogue search.

High-cardinality object candidates carry only compact hash/length keys; the deduplicated FName table also carries the dictionary fingerprint so an MD5 collision cannot collapse two names. A hash match is never authoritative: it is a candidate that must be checked against UEDB5 when source identity matters.

This avoids the V4 pattern where many different source strings and paths flowed through one large auto-increment `ue_terms` dictionary and were then repeated through several per-object lookup tables.

## 4. FName candidates

`ue_uedb5_name_candidates` is for catalogue FName discovery, not Name-table persistence.

Its cardinality is **one row per file and distinct normalized FName**, not one row per serialized Name-table entry. The row contains only:

- file ID;
- normalized name hash/length plus SHA-256 fingerprint for collision-safe deduplication;
- first matching Name-table index as a hydration hint.

Duplicate serialized Name entries therefore do not automatically create duplicate SQL rows. If a caller needs every matching serialized Name index, it reads/scans that file's UEDB5 name section after SQL identifies the candidate file.

Name text itself is not copied into this table; searchable text is shared through the distinct-key dictionary.
## 5. Object/export candidates

`ue_uedb5_object_candidates` is the general V5 form of the successful UT3 candidate-index approach.

Each row contains only:

- provider file ID;
- object kind (`export` or `cell_export`);
- source object/export index;
- normalized ObjectName hash/length;
- public-export hash when the source format supplies one.

The table must **not** contain class package/name, class/super/template index, outer index, object/package flags, serial offset/size, filter flags, preload ranges, raw `FPackageObjectIndex`, or any other source graph/serialization field.

Classic VerifyImport flow is therefore:

```text
required ObjectName -> object candidate indexes -> provider UEDB5 rows -> engine-specific VerifyImport
```

Zen PackageImport flow is:

```text
selected FPackageId provider + PublicExportHash -> export/cell candidate -> provider UEDB5 row
```

For duplicate candidate keys, SQL returns every relevant source index (or Epic-defined table order where the resolver requests it). It never decides class/outer/private-export validity.

This table is allowed to be O(exports) because an indexed object-to-export-index map is a demonstrated catalogue/dependency requirement. Its row and indexes must remain narrow because this is the highest-cardinality baseline V5 projection.

### High-cardinality physical widths

The migration DDL must preserve these widths unless a later measured contract explicitly changes them: ObjectName/FName lookup hashes `BINARY(16)`, key lengths `INT UNSIGNED`, Zen `PublicExportHash` `BINARY(8)`, file IDs `BIGINT UNSIGNED`, source/object indexes `INT UNSIGNED`, kind/classification/outcome codes `TINYINT UNSIGNED`, dependency package/object keys at most `VARBINARY(16)`, and the distinct search-key fingerprint `BINARY(32)`. Normalized searchable text exists only once in the global key dictionary, not in per-object rows.

No per-export `VARCHAR`, `TEXT`, `BLOB`, class identity, flags or serialization columns are part of the baseline object table.
## 6. Dependency edges

`ue_uedb5_dependency_edges` is a relationship projection of the authoritative UEDB5 `dependency_results` section. It is not a second import table.

Each row may retain only what indexed catalogue queries need:

- consumer file and source-kind/source-index key;
- dependency classification and canonical five-state outcome;
- compact required-package key and optional required-object key;
- selected provider file/object kind/object index when resolved.

The row deliberately omits required package/object strings, class identity, outer graph, flags, resolver reason text and source-specific raw structures. Those remain in UEDB5.

For classic-import `required_object_key`, the compact key must preserve `FName` identity semantics: case folding is allowed for the lookup accelerator, but serialized text is **not trimmed** before hashing. A literal whitespace-only object name therefore receives a real object key and is not collapsed to an absent object identity. This rule is specific to dependency identity; catalogue search candidates may continue to use their documented normalized search-name policy, with UEDB5 hydration remaining authoritative.

The outcome codes remain:

| Outcome | Code |
|---|---:|
| `missing` | 0 |
| `resolved` | 1 |
| `package_only` | 2 |
| `common` | 3 |
| `unresolved` | 4 |

Classification is separate: hard, optional, soft, build/cook, script, cell/Verse, load-order and runtime-derived.

This allows affected-file, reverse-dependency and missing-status queries without teaching SQL how to reproduce an engine loader.
## 7. Dependency package summaries

`ue_uedb5_dependency_packages` is intentionally denormalized because game-missing/package-summary pages need package-level status without scanning every dependency edge.

It is O(unique required package per requiring file), not O(imports × provider candidates).

It retains:

- game/file and compact package key;
- required package display name once at summary level;
- total, resolved, missing, package-only, common and unresolved counts;
- hard-missing and non-hard-missing counts;
- summary outcome and selected provider file when meaningful.

This is the correct place for repeated dashboard/listing counters. Detailed reason/provenance remains in the UEDB5 dependency result rows.

## 8. Object-path projection is opt-in

A baseline object candidate row does not carry path hash or path text.

If a measured production query cannot be served acceptably by ObjectName/FName candidates plus UEDB5 hydration, `ue_uedb5_object_path_candidates` may be enabled as a separate narrow table containing only:

- file ID;
- object kind/index;
- normalized path hash.

It must not acquire class/outer/flag columns. Keeping it separate means an object-path requirement does not silently add another indexed 16-byte value to every export in every installation.

Before enabling it, the implementation must document the exact query, measured need, expected row count/index size and why the normal candidate-hydration route is insufficient.
## Source detail that stays out of baseline SQL

The following are UEDB5/source-reader concerns and are prohibited from baseline per-object V5 projections merely for convenience:

- class package/name and class/super/template graph;
- outer graph;
- object/package flags;
- serialization offsets/sizes/layout sizes;
- preload/dependency range fields;
- script serialization ranges;
- filter flags and version-gated source members;
- raw `FPackageObjectIndex` / `TypeAndId` values;
- Zen export/dependency bundles and cell structures;
- complete import/export/name rows;
- resolver diagnostic/reason payloads.

A future query may project a derived key from these fields only when the query needs an index. It does not justify copying the source fields themselves.

In particular, V5 does **not** reproduce the V4 `ue_export_path_lookup` shape containing class-package/name, object flags and outer index, and does not reproduce `ue_legacy_export_identity_lookup` as a second VerifyImport record.

## Hash/key rule

Projection hashes are accelerators, not identities that authorize source matches.

The compact normalized name lookup uses the existing `md5-fname-ci-v1` normalization family. Optional path lookup uses `md5-fnamepath-ci-v1`. The distinct search-key dictionary also retains a SHA-256 fingerprint so its text dictionary is not dependent on an auto-increment term ID and can separate dictionary-key collisions deterministically.

A hash collision is permitted to produce extra SQL candidates. It must never cause a dependency/object match to be accepted without UEDB5 source verification.
## Projection growth gate

No new `ue_uedb5_*` table, column or secondary index is accepted merely because the data is available in UEDB5.

Every proposed addition must document all of:

1. the concrete catalogue/runtime query it accelerates;
2. the index access pattern used by that query;
3. why reading candidate UEDB5 rows after a narrower SQL lookup is insufficient;
4. expected cardinality relative to files, names, exports or dependencies;
5. estimated row width and secondary-index amplification;
6. whether the projection is baseline or optional;
7. how it is deterministically regenerated from UEDB5;
8. how projection/file disagreement is detected and repaired.

For high-cardinality tables, adding a source-shaped column is presumed rejected unless measurements demonstrate otherwise.

The preferred optimization order is:

```text
improve candidate key/index
-> hydrate fewer UEDB5 blocks/rows
-> cache bounded read results
-> only then consider another SQL projection
```

not "copy more of the export/import row into MySQL."

## Publication and repair invariants

All V5 SQL projections are disposable derivatives. A projection rebuild must be possible from UEDB5 plus catalogue-only policy data without opening UEDB4.

Per-file publication must replace that file's V5 projection rows atomically or transactionally. Stale rows from an older UEDB5 payload must never coexist with the current registration.

Projection verification compares candidate/edge/summary coverage to the UEDB5 manifest and sections, but the SQL row is never used to repair missing source fields in UEDB5.
## Cutover relationship

Step 5 defines migration `202609300001_uedb5_staging_registration.php`, which prepares nullable V5-only columns on `ue_file_metadata` and creates the baseline side-by-side `ue_uedb5_*` schema. Applying that migration does not change an existing live metadata row from format 4 to format 5.

`PdoUedb5StagingRegistrationRepository` registers a verified staged container only in `ue_uedb5_files`, after proving the corresponding verified catalogue file still has a live UEDB4 registration. V5 projection tables foreign-key to the staged registration so deleting it cascades staged projection rows.

During migration the dedicated V5 tables may be populated and verified alongside V4. Production queries continue using the current V4 registration/projections until the explicit cutover step.

At cutover, runtime repositories may switch to the verified V5 tables or an atomic final-name replacement strategy may be chosen. The pre-cutover implementation must not become a permanent `V5 else V4` fallback.

After cutover and verification, V4-only lookup tables can be retired rather than retained as a second source-shaped database.

## Contract verification

`verify-uedb5-sql-projection-contract.php` asserts at minimum that:

- the baseline table set is fixed and uses the `ue_uedb5_*` coexistence namespace;
- the five dependency outcomes and dependency classifications are explicit;
- object candidates contain only file/kind/index/name-key/public-hash fields;
- class/outer/flags/serialization fields are absent from object and dependency-edge projections;
- FName candidates deduplicate normalized names within a file and do not repeat name text;
- search text is dictionary-level rather than copied per object;
- object-path projection is disabled by default;
- dependency summaries can drive missing-package pages without full edge scans;
- the UT3 SQL-candidate -> UEDB-hydration pattern remains the model;
- production remains UEDB4 while this contract is being established.

Any later V5 SQL migration/writer must extend this verification with physical DDL/index checks and measured row-count/size checks before publication is considered complete.
