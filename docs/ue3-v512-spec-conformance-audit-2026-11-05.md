# UE3 / UT3 v512 — specification compliance code audit (5 November 2026)

## Scope and evidence policy

Read-only comparison of the active UnrealDB UE3 parser, the UT3-specific UEDB5 snapshot writer, import name/context preprocessing, class identity, provider selection, and production metadata/website contracts against the independent UE3 package specification and UT3 v512/licensee-0 addendum.

This is **not** an audit of unrelated native mesh, audio, texture, rendering or gameplay loading. No original packages or live PRD database records were modified. All negative mutation tests use disposable copies of SHA-256-pinned original UT3 bytes.

Existing accepted source and original-byte evidence reused: `tests/evidence/ue3/ue3-original-ut3-v512-all-six-metadata-codepaths-20261102.json`; website and PRD read-model evidence: `tests/evidence/ue3/ue3-prd-v14-website-source-closure-20261105.json`. New narrow-boundary result: `tests/evidence/ue3/ue3-v512-spec-boundaries-20261105.json`. These are evidence associated with existing contracts, **not new test IDs**.

## Production call paths reviewed

1. Selected game/source -> `CatalogReaderResolver` -> `catalog/parsers/UE3CatalogReader.php` -> `CatalogUE3PackageReader`.
2. `Uedb5SourceSnapshotFactory` -> `Uedb5Ut3SnapshotBuilder` -> immutable, source-labelled UEDB5 sections.
3. `CatalogParsedPackageMetadataSnapshotBuilder` and `CatalogLegacyNameMapPreprocessor` for effective UT3 name flags, 127-character runtime names, and numbered `FName` references.
4. `CatalogCompactIdentityEnricher` for early-UE3 import fixups and exact class package/name identity.
5. `Uedb5ClassicDependencyResolver`, `PdoUe3VerifyImportProjectionResolver`, `PdoUedb5PhysicalProviderSelector` for source-selected physical provider and unresolved-mount classification.
6. Existing Pass2 SQL publication / current-policy read gates and website read-model evidence were inspected but *not* re-executed end-to-end during this audit.

## Compliance matrix

| Documented requirement | Present implementation | Review result |
|---|---|---|
| Correct later-family summary, tag, endianness, v512 fields and absence of post-512 extras | `EpicUE3PackageReader.php` summary and version gates | **MATCH for UT3 v512** on prior seven originals |
| Name string and full 64-bit flags | UE3 reader; UT3 snapshot `u64Hex` | **MATCH** on 35,593 original names |
| 64-bit context bits, effective None filtering and runtime 127-character cap | UT3-specific context in `CatalogLegacyNameMapPreprocessor`, snapshot and dependency resolver | **MATCH for audited UT3 profile**; context depends on caller |
| FName index plus internal number (internal 1 displays _0) | Reader plus effective-name path | **MATCH** in accepted table evidence |
| Four import fields and signed package indices | Reader and source-selected import fixups | **MATCH** on 17,904 imports |
| Export records with archetype, full 64-bit ObjectFlags, component map, export and network fields | UE3 reader and UT3 snapshot | **MATCH** on 34,635 exports |
| Every nonempty export payload span within logical archive | Previously checked only negative size/offset | **DEFECT FIXED**: new `ue3.export_payload_out_of_bounds` |
| Do not parse early versions as later-family records; reject unverified 472-490 | Previously accepted versions 1..867 with one later serializer | **DEFECT FIXED**: generic reader now explicitly accepts only 491..867; older implementation remains open |
| Do not label another version/licensee as UT3 v512/0 | UT3 snapshot builder previously lacked independent source policy guard | **DEFECT FIXED**: 513/0 and 512/1 rejected |
| Exact class package, name, parent and RF_Public when matching candidate exports | `CatalogCompactIdentityEnricher` and `PdoUe3VerifyImportProjectionResolver` | Previously **source/fixture-verified**; not independently re-executed here |
| Select actual physical package before declaring resolved | Source-scoped UE3 provider selection; no mount inferred from indexed candidates | **MATCH in prior original-PRD conditional evidence**; native mount order remains unavailable |
| Export dependency list at `DependsOffset` | Offset retained; list contents not decoded by the parser | **NOT IMPLEMENTED**, and not a current UnrealDB metadata or dependency-publication operation |
| Compression chunk metadata and zlib/LZO/LZX reconstruction | UE3 parser implements supported methods and strict diagnostics | **PARTIAL**: genuine compressed-package comparison not established in this review |
| Native object-specific payloads | Only payload spans retained for catalog | **OUT OF CURRENT PRODUCT SCOPE**, not falsely marked parsed |

## Reproducible new findings and results

### 1. Positive export payload range beyond EOF — corrected

Genuine original file ID 149701, SHA-256 `9761e936c609479356c89bfbe9d0ff22f9d2893e4b32029416b0922fae082214`, had physical/logical size **1,776,698** and valid first export `SerialSize=44`, `SerialOffset=590649`. In a disposable copy only, the first export's stored offset word at file position **274693** was changed to **1,776,798**, 100 bytes beyond logical EOF. Original code returned **zero issues** and exposed all 4,031 exports, incorrectly treating this as a valid file.

The repaired reader rejects that copy with `ue3.export_payload_out_of_bounds`. Validation uses subtraction, rather than unchecked `offset + size`, and applies only when `SerialSize > 0` so harmless zero-sized records are not rejected.

### 2. Early and unverified versions decoded with later-family schema — corrected by fail-closed admission

The UE3 engine document defines separate **225–471** early and **491–867** later package layouts and does not assign a guessed schema to **472–490**. The existing reader unconditionally read the later summary for every version from 1 through 867. The active reader now rejects versions outside the implemented later-family range with `ue3.unsupported_version_layout`. Disposable header-only mutations to 350 and 480 are rejected. This does **not** implement support for valid early-family packages.

### 3. UT3 game source policy could be misassigned — corrected

The UT3 UEDB5 snapshot builder previously labelled successfully parsed **513/licensee 0** and **512/licensee 1** packages as `ue3-ut3-jan2008-package-v512`. These are not within the audited game-source profile. Both are now rejected before constructing a source-labelled snapshot. A genuine original 512/licensee 0 package still successfully generates 5,974 names, 3,134 imports and 4,031 exports.

### 4. All seven preserved unmodified originals remain accepted

The dedicated regression uses all seven untouched genuine UT3 version-512/licensee-0 fixtures and verifies their manifest SHA-256, version/licensee, and name/import/export counts. **7/7 PASS; 35,593 names; 17,904 imports; 34,635 exports.** These checks are directly affected regression evidence for the changed reader, not a replacement for the accepted independent byte-level original oracle.

## Remaining qualification and priorities

- **UE3 225–471:** documented, but not implemented by the current later-family UE3 parser. A source-selected early reader would be needed before advertising full historical UE3 coverage.
- **Physical mount-order context:** not available from independent metadata; do not silently resolve or mark missing. Existing conservative unresolved handling is correct.
- **Compression:** original, genuinely compressed UT3 package with known engine behaviour is needed before claiming full ZLIB/LZO/LZX native parity. Avoid synthetic tests unrelated to the current product.
- **DependsOffset:** recorded in summary but export-indexed dependency array not materialized. No current UnrealDB operation consumes it; only implement if an actual consumer is identified.
- **Object payloads:** outside UnrealDB's metadata and index reading contract.
- **Source hash drift:** six historic UE3 contracts contain some earlier source hashes from later project iterations. Historical PASS evidence remains scoped to its pinned versions; it must not be silently treated as source-identical certification for every later provider, publisher or website change. This review newly verifies only the changed parser and UT3 snapshot boundary.
- **Cross-engine coupling:** the shared name-map preprocessor contains UE1/UE2 and UE3 engine-specific masks. It is not the concrete UE3 file reader, but it should be isolated or made a genuinely engine-neutral primitive in a future architecture review if the strict all-engines-or-none sharing requirement is applied to metadata helpers.

## Change and deployment boundaries

Changed DEV sources:

- `catalog/parsers/EpicUE3PackageReader.php`
- `catalog/src/Infrastructure/Metadata/Uedb5Ut3SnapshotBuilder.php`

Test files and disposable mutations remain under `D:\Temp\UnrealDB\ue3\spec-review-20261105`. No PRD SQL writes, UEDB5 resnapshot, original binary mutation, native gameplay calls or new contracts.

**Conclusion:** The documented UT3 version-512 metadata path is broadly aligned with the previously accepted source/byte evidence. Three concrete safety/provenance defects discovered in this review are corrected and confirmed against positive original cases and negative mutated copies. This is narrower than certification of the complete UE3 file format or all compiled UT3 runtime paths.
