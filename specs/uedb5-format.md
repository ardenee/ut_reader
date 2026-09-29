# UEDB5 Metadata Format Contract

## Status and scope

This document is the normative format contract for UnrealDB metadata format version 5 (`UEDB5`).

UEDB5 is a source-shaped metadata cache. It is not an archival replacement for the original Unreal package/container bytes, and it is not a translation layer over UEDB4.

The governing migration and rebuild path is:

`authoritative Unreal package/container bytes -> source-aligned reader -> UEDB5 -> SQL projections`

UEDB4 must never be treated as the authoritative source for a UEDB5 field that UEDB4 did not retain losslessly.

This contract freezes the UEDB5 container framing, common logical model, dependency outcome/provenance model, and engine-family extension rules. Per-engine package and dependency specifications remain authoritative for the meaning and version gates of source fields.

Production runtime remains UEDB4 until the separate UEDB5 migration, validation, and cutover steps are complete.

## Authoritative local source roots

Engine source:

- UE2 / UE2.5: `L:\Source\Engine\UE2`
- UE3: `L:\Source\Engine\UE3`
- UE4: `L:\Source\Engine\UE4`
- UE5: `L:\Source\Engine\UE5`
- UDK: `L:\Source\Engine\UDK`
Game source:

- Unreal: `L:\Source\Games\Unreal`
- Unreal II: `L:\Source\Games\Unreal II`
- UT99: `L:\Source\Games\UT99`
- UT2003: `L:\Source\Games\UT2003`
- UT2004: `L:\Source\Games\UT2004`
- UT3: `L:\Source\Games\UT3`
- UT4: `L:\Source\Games\UT4`

Tool source: `L:\Source\Tools`.

The current UE5 classic audit uses `L:\Source\Engine\UE5\UE 5.8.3`, branch `release`, commit `396c9f059903aed5fec78ecd3d437a40c6415368`.

## Format invariants

1. Serialized identity is retained before derived identity.
2. Raw package/object graph references are retained even when a derived path is also stored.
3. Version-gated absence is distinct from a serialized zero, false, None, or empty value.
4. Source load-time fixups are retained separately from the serialized value when they change identity.
5. Derived paths, hashes, normalized names, and SQL terms are accelerators and never replace the source fields used to derive them.
6. One physical provider file must satisfy a source dependency decision; UnrealDB must not combine exports from different same-name providers into a synthetic provider.
7. Runtime/configuration behavior that cannot be reconstructed from authoritative bytes and retained container provenance is recorded as unresolved rather than guessed.
8. Section schema identifiers are immutable contracts. Changing the meaning of an existing field requires a new section schema identifier.
9. A change to the physical UEDB5 framing or incompatible manifest semantics requires a later container format version; it must not silently reinterpret format version 5.

## Physical container framing
UEDB5 files use the extension `.uedb5` and begin with a fixed 20-byte little-endian header:

| Offset | Size | Field | Required value |
|---:|---:|---|---|
| 0 | 8 | magic | `UEDBM5` followed by two NUL bytes |
| 8 | 2 | format version | unsigned LE `5` |
| 10 | 2 | codec | unsigned LE `2` (`gzip-blocks`) |
| 12 | 4 | manifest length | unsigned LE byte length of the JSON manifest |
| 16 | 4 | reserved | unsigned LE zero |

The header is followed immediately by the UTF-8 JSON manifest and then by the compressed section blocks in manifest order. No bytes may precede the header or follow the final declared block.

The required manifest identity is:

- `format = "unrealdb.uedb5-metadata"`;
- `format_version = 5`;
- `codec = "gzip-blocks"`;
- `payload_encoding = "json-rows-v1"`;
- positive `block_size`;
- non-empty `package_family`;
- non-empty `source_policy`;
- `file` identity object;
- `counts` keyed by section name;
- `section_schemas` keyed by section name where a schema is defined;
- `sections` keyed by section name.

`file` contains at least `id`, `game_id`, `package_name`, and `original_name`. These are catalog identity/provenance fields; they do not replace serialized package identity in the source-shaped sections.
### Block framing and integrity

Each logical section is an ordered row list. Rows are divided into contiguous blocks. The uncompressed block payload is one JSON object of the form `{"rows":[...]}` and is gzip-compressed independently.

Every block descriptor contains:

- `row_start`;
- `row_count`;
- `offset`, relative to the first payload byte after the manifest;
- `compressed_length`;
- `uncompressed_length`;
- `sha256`, the SHA-256 of the exact compressed block bytes as 64 hexadecimal digits.

Blocks for a section must cover rows contiguously from zero with no overlap or gap. Payload offsets must likewise be contiguous in manifest order. The manifest `counts[section]` must equal the sum of that section's block row counts.

Verification must reject wrong magic/version/codec, non-zero reserved header data, malformed manifest JSON, unknown/mismatched section counts, discontinuous block offsets or row ranges, block checksum failures, decompression/JSON failures, wrong row counts, truncated bytes, and trailing bytes.

The metadata registration layer must also retain a SHA-256 over the complete `.uedb5` file bytes. This whole-container hash is distinct from the per-block SHA-256 values in the manifest.

### Package family and source policy

`package_family` selects the representation model before source rows are interpreted. Required families are at least:

- `classic-linkerload` for package-table formats using name/import/export linker structures;
- `zen-iostore` for UE5 Zen package headers and IoStore package-store identity.

`source_policy` selects the exact audited engine/game behavior inside that family. It must identify a documented source revision/profile strongly enough that the reader, persistence schema, and dependency resolver cannot silently switch to another game's rules.
A source policy may reuse the same physical section layout as another policy only when the field meanings and presence rules are identical. Dependency behavior still follows the selected source policy, not the section name alone.

## Canonical value representation

UEDB5 JSON must not lose source integer width or signedness where either can affect identity.

- signed package indices and ordinary signed offsets/counts use JSON integers when they are within PHP's signed 64-bit range;
- bit-pattern `uint32` values that must retain exact bits use fixed-width 8-hex-digit strings in the source-specific schema;
- unrestricted `uint64` identities/hashes/flags use fixed-width 16-hex-digit strings and must never pass through a PHP signed integer;
- byte arrays/hashes that are not naturally textual use an explicitly documented hexadecimal or binary representation in their section schema;
- source widths are recorded when the same canonical field can be serialized at different widths between engines.

For version-gated fields, the schema must distinguish `not serialized` from `serialized zero/false/None`. A nullable value alone is insufficient when null could also be a legitimate loaded value; use an explicit presence field or a schema whose version guarantees presence.

### FName identity

A serialized FName reference must retain, as applicable to its source revision:

- original name-table index;
- original instance number when the source serializes one;
- decoded text used by that source reader;
- whether an instance-number component was serialized for that schema;
- both serialized and effective loaded identity when source fixups can change the loaded name.

For schemas where index+number are serialized, the canonical nested identity is `name_index`, `number`, and `text`. A reader must not collapse `Foo_1` and `Foo` to the same base text, and must not replace the raw index/number with only a normalized string.

Name-table rows and FName references are different structures and remain different UEDB5 records.
## Common classic-package logical sections

Classic source policies use ordered sections whose exact JSON fields are fixed by their `section_schemas` identifiers. The common semantic minimum is below; an older engine is not required to fabricate a field that its source never serialized.

### `summary`

Exactly one source-shaped row retains the package identity needed to select and verify the serializer:

- serialized package tag and byte order;
- serialized engine/package version components and licensee version;
- effective parser version/profile when unversioned or externally selected;
- custom versions when the source serializes them;
- package flags;
- package GUID/persistent identity fields present in that revision;
- generations/heritage identity where present;
- source table counts and the source-specific summary fields needed to verify table interpretation;
- compression/encryption dispatch fields that affect how package data is read;
- source-specific package identity fields used by dependency or provider selection.

Offsets that merely point to data normalized into another verified UEDB5 section do not need to be duplicated for historical completeness, but the reader must still parse them correctly before consuming the source package.

### `names`

Rows remain in original name-table order and retain the source name index, decoded text, serialized offset, serialized flags/hashes when present, and any source-specific string/entry metadata required to distinguish the serialized value from its effective loaded identity.

The name table must never be deduplicated or reordered.
### `imports`

Rows remain in original import-map order and retain at least the source fields required by that policy:

- import-map index and the derived signed package reference for that row;
- serialized record offset when available;
- `ClassPackage` identity;
- `ClassName` identity;
- raw signed outer/package index exactly as interpreted by that source serializer;
- `ObjectName` identity;
- explicit package identity fields such as UE4/UE5 `PackageName` when serialized;
- serialized/effective variants when the source performs a load-time fixup;
- optional/import flags and any other source fields used by that policy's dependency rules.

The raw outer graph is authoritative. A derived package name or object path may be cached in another field/section, but must not replace the raw signed reference.

### `exports`

Rows remain in original export-map order and retain at least:

- export-map index and the derived signed package reference for that row;
- serialized record offset when available;
- raw class index;
- raw super index where that source has one;
- raw template/archetype index where that source has one;
- raw outer index;
- `ObjectName` identity;
- complete object flags without truncation, plus serialized flag width/semantics where generations differ;
- serialization size and offset with their source width rules;
- package/export flags and source booleans that affect loading, visibility, public identity, filtering, inheritance, or dependency behavior;
- preload/dependency range fields when serialized;
- script serialization offsets when serialized.

Derived class names, local paths, full object paths, and path hashes are accelerators only. The raw class/super/template/outer graph remains first-class UEDB5 data.
### Additional classic sections

A source policy adds separate ordered sections rather than overloading imports/exports when the source representation is distinct. Examples include:

- `soft_package_references`;
- `preload_dependencies`;
- cell/Verse resources;
- import type hierarchies;
- data-resource/payload ownership records;
- source-specific package summary blocks whose entries have independent identity.

Hard imports, optional imports, soft references, build/cook dependencies, preload/load-order edges, cell references, and runtime-derived references must remain distinguishable.

## Cross-engine preservation requirements

The per-engine package/dependency specifications remain authoritative. UEDB5 must retain every field those rules require to reproduce deterministic file-backed loading/dependency identity.

| Source family | UEDB5-specific preservation requirement |
|---|---|
| UE1 / UT99 | preserve compact-index-era raw references, source name-entry flags/string rules, GUID/generation identity, and the pre-68 heritage-table distinction where applicable |
| Unreal II / UE2 | preserve the source's fixed import parent index vs compact-index distinctions and enough export flags/outer/class identity to reproduce Unreal II's dependency rules rather than UE2.5 rules |
| UE2.5 / UT2003 / UT2004 | preserve each game's versioned name/import/export layout and any source-specific loaded-name behavior; do not flatten these games into one generic UE2 policy |
| UE3 / UT3 | preserve the complete serialized 64-bit `ObjectFlags`, exact class package/name identity, and mixed import/export outer graph required by `VerifyImportInner` |
| UE4 4.27.2 | preserve serialized and effective import `PackageName`, raw `FPackageIndex` graph, modern FName identity, preload dependencies, and version-gated export fields |
| UE5 classic | preserve the dedicated UE5 version model, `PackageName`, `bImportOptional`, raw graph, public-hash semantics, and all presence-gated fields documented in the UE5 classic specifications |
| UE5 Zen / IoStore | use the separate `zen-iostore` family and the unsigned-64/package-store model below; never flatten it into classic `FPackageIndex` imports |

A future builder is conformant only if it can prove that every source field needed by its package and dependency specification is represented losslessly or explicitly marked unavailable/unresolved.
## UE5 Zen / IoStore logical contract

Zen/IoStore stores package identity separately from classic LinkerLoad. The following source-shaped information is mandatory when present in the audited UE5 source/profile.

### Package and import identity

The package metadata must retain:

- raw `FPackageId` as fixed-width 16-hex-digit unsigned identity;
- Zen/package file version, licensee version, and custom versions needed to interpret the header;
- package flags and package name identity supplied by the Zen/package-store representation;
- ordered imported package IDs from the package-store entry;
- ordered imported public export hashes from the Zen package header;
- ordered import map entries as raw 64-bit `FPackageObjectIndex::TypeAndId` values;
- decoded object-index type: Export, ScriptImport, PackageImport, or Null;
- for PackageImport, decoded imported-package index and imported-public-export-hash index;
- for ScriptImport, the raw source hash/identity encoded by the object index;
- imported package names when the Zen version supplies them.

A PackageImport provider key is the source pair:

`ImportedPackageIds[ImportedPackageIndex] + ImportedPublicExportHashes[ImportedPublicExportHashIndex]`

A package name is descriptive identity and must not replace that key.

Recommended section roles are `zen_summary`, `zen_imported_packages`, `zen_imported_public_export_hashes`, and `zen_imports`, each with a versioned section schema. Exact row field names are fixed by those schema identifiers when the Zen writer is implemented.
### Zen exports and load graph

For every source `FExportMapEntry`, retain:

- local export index;
- cooked serial offset and size;
- object-name identity;
- raw outer, class, super, and template `FPackageObjectIndex` values;
- 64-bit `PublicExportHash`;
- complete serialized object flags;
- export filter flags and other source fields that change visibility/public identity.

The source load graph remains separate from object identity. Retain:

- export bundle entries with local export index and Create/Serialize command type;
- dependency bundle headers and their per-command dependency counts;
- dependency bundle entries and raw local import/export package-index identity;
- any version/header offsets needed to verify how those arrays were sliced from the package header.

Recommended section roles are `zen_exports`, `zen_export_bundle_entries`, `zen_dependency_bundle_headers`, and `zen_dependency_bundle_entries`.

### Cell/Verse and ScriptImport identity

Zen cell import/export maps remain separate from ordinary UObject import/export maps. Retain raw cell `FPackageObjectIndex` references, serialized cell export identity, public-export hash, class information, and source dependency ranges where present.

ScriptImport resolution requires the source script-object identity table/context. UEDB5 must retain the relevant script object entries or a stable, integrity-checked provenance reference to the exact script-object table used for resolution. A ScriptImport must not be converted to a guessed package/object name.

Recommended section roles are `zen_cell_imports`, `zen_cell_exports`, and `zen_script_objects` or an equivalent versioned provenance section.
### IoStore package-store provenance

A standalone extracted Zen package is not fully authoritative when its PackageImport references require package-store context owned by the container header.

For file-backed IoStore, UEDB5 must retain or durably reference:

- `ContainerId`;
- the package's `FPackageId` and package-store entry;
- the exact ordered imported package IDs used by that store entry;
- package-store flags;
- optional-segment package IDs and the package's optional-segment store-entry data;
- localized-package mapping applicable to the package;
- redirects applicable to the package, including source package ID/name and target package ID;
- soft package references applicable to the package;
- stable identity/integrity of the `.utoc`/container metadata from which the store entry came.

The entire container header should not be duplicated into every package's `.uedb5`. Shared IoStore metadata may be stored once, but each package UEDB5 must contain a stable provenance key/hash plus the package-specific ordered data required to reproduce its references without guessing.

The `.utoc` table of contents and corresponding `.ucas` data container(s) are therefore part of the authoritative input for full file-backed Zen verification.

## Dependency result and provenance contract

Raw source references and derived dependency decisions are separate. UEDB5 retains raw imports/references in their source sections and stores deterministic resolution results in a dedicated versioned dependency-results section.

SQL term IDs are projection artifacts and must not be stored as the authoritative UEDB5 identity.
Each dependency-result row must identify, directly or by stable row reference:

- dependency kind/classification;
- source section and source row/index that created the requirement;
- required package identity when one exists;
- required object identity when one exists;
- whether the requirement is hard, optional, soft, build/cook, script, cell/Verse, load-order, or runtime-derived;
- canonical outcome;
- selected physical provider file when applicable;
- selected provider export/object identity when applicable;
- resolver/source-policy identity;
- deterministic reason/provenance code explaining the decision.

The canonical outcome set is exactly:

- `resolved` — source-aligned deterministic rules selected a valid physical provider/object;
- `package_only` — package-level identity/provider is known, but there is no valid object-level result to claim;
- `common` — the dependency is satisfied/excluded by explicit UnrealDB common/base-game policy, with that catalog-policy provenance recorded;
- `missing` — the applicable deterministic source/catalog policy was fully evaluable and no valid provider/object exists;
- `unresolved` — a decision cannot be made from the authoritative bytes plus retained provenance because required runtime/configuration/source state is unavailable or the representation is not yet supported.

`unresolved` is not a synonym for `missing`. A runtime-only/native/transient/redirect/config path that UnrealDB cannot reproduce must not be counted as a proven missing dependency.

Likewise, optional/soft/build/load-order requirements may have an outcome of `missing` while remaining non-hard through their dependency classification. Hard-missing counters and download blockers must filter by both dependency classification and outcome rather than treating every missing-class result identically.
For compact SQL/status projection, the stable status code mapping is:

| Outcome | Code |
|---|---:|
| `missing` | 0 |
| `resolved` | 1 |
| `package_only` | 2 |
| `common` | 3 |
| `unresolved` | 4 |

The UEDB5 dependency row remains authoritative; the numeric code is only a compact projection representation.

### Resolution provenance

Provider resolution provenance must make it possible to audit why a row has its outcome without reconstructing evidence from SQL terms. At minimum retain the source-policy/resolver revision, the source record identity, selected physical provider file identity, selected provider export/public-hash identity where applicable, and a stable reason code.

When multiple same-name provider files exist, provenance records the one physical provider that was evaluated/selected. A result assembled from objects spread across multiple provider files is invalid.

Catalog-only decisions such as base-game/common exclusion must identify that catalog policy separately from Epic source behavior. A source rule and a catalog distribution policy must never be represented as if they were the same provenance.

## SQL projection boundary

UEDB5 is the primary store for source-shaped package metadata. MySQL contains only indexes and materialized relationships that are justified by catalog queries.

Suitable V5 projections include package/provider identity, candidate ObjectName/FName lookup, normalized/hash search keys, dependency edges/package summaries, and minimal object-path candidate indexes where measured queries require them.

Full class/outer graphs, complete export records, object flags, serialization offsets/sizes, version-gated source fields, Zen bundle structures, and other source detail stay in `.uedb5` by default.

SQL candidate lookup must return indexes/keys into UEDB5; SQL must not become a second authoritative source-shaped export table.
## Per-file conformance and validation

A UEDB5 file is conformant only when all applicable checks pass:

1. physical header, manifest, block ordering, block hashes, and whole-container registration hash are valid;
2. manifest file identity matches the catalog file being validated;
3. `package_family`, `source_policy`, and every referenced `section_schema` are recognized;
4. source table/section counts match the authoritative reader output;
5. every raw name/package/object index is range-valid under the selected source policy;
6. package identity/version/GUID fields match the authoritative source parse;
7. version-gated presence state matches the source serializer rather than a defaulted value;
8. complete object flags and unsigned-64 identities compare losslessly to source;
9. dependency-result coverage accounts for every source dependency/reference that the policy says UnrealDB must classify;
10. dependency outcomes/provenance agree with the selected source resolver and one physical provider policy;
11. SQL projections, when present, can be regenerated from the UEDB5 file and agree with it;
12. validation does not require opening or interpreting a UEDB4 container.

For classic import maps, every serialized import must be either represented by an import-derived dependency result or explicitly classified by the policy as non-dependency/runtime-only evidence. Silent omission is invalid.

For Zen, every PackageImport must be resolvable back to the exact ordered imported-package ID and public-export-hash tables used by its package object index, or the dependency result must be `unresolved` with provenance explaining the missing container context.

## Schema evolution inside format 5

A section schema identifier fixes field names, field meanings, presence rules, and canonical encodings for that section version.

Adding a new optional engine-specific section or introducing a new section-schema version is allowed within container format 5 when the physical framing and manifest semantics do not change. Existing schema identifiers must never be redefined in place.

If a future requirement cannot be represented without changing the physical header, block framing, manifest interpretation, or an already-published schema's meaning, it requires a new schema identifier or a later UEDB container version rather than an implicit UEDB5 rewrite.

## Migration/cutover invariant

The catalogue-wide V5 migration must re-read authoritative package/container bytes except where a source policy has been explicitly audited and proven to have every UEDB5-required serialized field already present losslessly in the prior metadata. Missing source identity must fail migration; it must never be inferred from a derived V4 path/hash/string.

Staging must not mutate or destroy the working V4 runtime. Until the atomic cutover, the intended coexistence is:

```text
production runtime = UEDB4 only
migration/staging  = UEDB5 only
```

The production runtime must not become a permanent `try V5, else V4` compatibility stack. Cutover occurs only after every verified file has validated V5 metadata and the V5 projections/dependencies are complete. V4 runtime code, registrations, files, and migration-only compatibility code are then retired in their dedicated cleanup step.

## Current implementation boundary

As of this contract:

- the isolated format-5 container foundation is implemented by `Uedb5MetadataContainer` and `Uedb5MetadataStagingReader`;
- classic UE5 5.8.3 source-shaped staging persistence is implemented by `Uedb5Ue5ClassicSnapshotBuilder`;
- deterministic file-backed classic UE5 `VerifyImportInner` staging resolution is implemented by `Uedb5Ue5ClassicVerifyImportResolver`;
- production metadata registration/runtime remains UEDB4;
- UE1/UE2/UE3/UE4 V5 source-reparse builders remain to be implemented;
- UE5 Zen/IoStore ingestion and UEDB5 writers remain to be implemented;
- V5 SQL publication, catalogue migration, cutover, and V4 retirement remain later steps.

The current UE5 classic staging resolver uses internal labels such as optional_missing and runtime_only. Those are staging diagnostics, not canonical persisted UEDB5 outcomes. A production V5 dependency writer must normalize them to the five-state contract above, for example missing plus optional classification and unresolved plus runtime-derived classification, while retaining the detailed reason/provenance.

Implementation status does not weaken the normative format requirements above. A not-yet-implemented section remains required before that source family can be declared fully UEDB5-capable.

## Normative source/specification links

Use the source-specific documents below to interpret the UEDB5 fields; this file defines storage/preservation requirements, not replacement engine behavior:

- `ue1-ut99-retail-v1400-package-format.md` and `ue1-ut99-retail-v1400-dependency-resolution.md`;
- `unreal2-ue2-package-format.md` and `unreal2-ue2-dependency-resolution.md`;
- `ue2.5-unreal-warfare-package-format.md` and `ue2.5-unreal-warfare-dependency-resolution.md`;
- `ut2003-v2107-package-format.md` and `ut2003-v2107-dependency-resolution.md`;
- `ut2004-package-format.md` and `ut2004-dependency-resolution.md`;
- `ue3-udkultimate-package-format.md`, `ue3-udkultimate-dependency-resolution.md`, and `ut3-v512-dependency-resolution.md`;
- `ue4-4.27.2-package-format.md` and `ue4-4.27.2-dependency-resolution.md`;
- `ue5-5.8.3-classic-package-format.md` and `ue5-5.8.3-classic-dependency-resolution.md`;
- `uedb5-metadata-requirements.md` for the detailed UE3/UE4/UE5 source audit and implementation checklist.

Where this format contract and a source-specific package/dependency document appear to disagree about engine behavior, the authoritative engine/game source and its source-specific specification control the behavior; the UEDB5 schema must then be versioned or corrected without silently discarding source identity.
