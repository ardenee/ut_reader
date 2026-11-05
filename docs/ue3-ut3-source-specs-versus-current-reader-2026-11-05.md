# UE3 + UT3: independent source specifications versus UnrealDB implementation

**Audit date:** 5 November 2026
**Scope:** UnrealDB's actual UE3/UT3 package-header, name/import/export, compression, UEDB5 metadata and VerifyImport/provider paths.
**Independent documents reviewed:** *Unreal Engine 3 Package File Format Specification, edition 1.1* and *UT3 Game-Specific Package Format Addendum, edition 1.1*.
**Important separation:** The two specifications describe **engine source and game source only**. This report, and only this report, compares those documents with UnrealDB. Game scripts do not introduce new UE3 wire fields; UDK/Core remains engine-level authority, not UT3 game authority.

## Outcome

**UT3 v512/licensee-0 metadata reader: conforms to the tested original package format.** The current real PHP parser read **89 of 89** installed files that exactly match every Unreal-package member of the retained Patch 5 installer. Every one has v512/licensee 0 and no parser validation issues, and all summary name/import/export counts match the decoded arrays.

| Genuine Patch 5 package metric | Result |
|---|---:|
| Original SHA-256-matched packages processed | 89 |
| Structural reader successes / failures | 89 / 0 |
| Name entries | 98,769 |
| Import records | 19,065 |
| Export records | 285,529 |
| Compressed / uncompressed packages | 49 / 40 |
| Compression-map chunks | 709 |
| Engine/licensee package versions | 89 at 512 / 0 |

These checks **do not constitute a new independent compiled-engine byte differential**. Existing independent field/offset proof for seven genuine packages (35,593 names, 17,904 imports, 34,635 exports, zero field mismatches) remains authoritative. No source package, production SQL, game install or UEDB5 file was changed.

## Implementation-path comparison

| Source-specified operation | Actual UnrealDB path | Current result | Qualification |
|---|---|---|---|
| Select engine-specific package reader | `CatalogReaderResolver::resolve` → `UE3CatalogReader.php` → `EpicUE3PackageReader.php` | **MATCH** | UE3 parser is siloed from UE1, UE2, UE4, UE5 |
| Recognise package tag, packed version, byte order | UE3 `CatalogUE3PackageReader::parse` | **MATCH for supported later family** | Swapped-tag branch exists; the 89 installed Patch 5 samples only prove their observed byte order |
| Read v512 summary and version gates | UE3 `parse`, `validateSummary` | **MATCH for UT3 512/0** | No later cross-level/thumbnail/additional-cook fields erroneously read from v512 |
| Read raw name and FName data | UE3 `readNames`, `fname`, `name` | **MATCH for tested UT3** | Raw 64-bit flags preserved as two words; instance numbers preserved |
| Early UE3 runtime name-context filtering | `CatalogLegacyNameMapPreprocessor`, game-selected parsed metadata builder | **MATCH within selected UT3 512/0 context** | All client/server/editor contexts and 127-character effective limit; raw serialized names remain intact; later UDK semantics deliberately not applied to UT3 |
| Read imports and exports | `readImports`, `readExports` | **MATCH for tested UT3** | Source order, 64-bit flags, archetype, component map at v512, generation counts and complete export payload span guard |
| UT3 metadata profile/version admission | `Uedb5Ut3SnapshotBuilder::build` | **MATCH** | Exactly 512/licensee 0; foreign version/licensee rejected rather than source-policy relabelled |
| Compressed physical→logical package offsets | `inflatePackage`, `inflateChunk`, `inflateBlock` | **MATCH for tested UT3 LZO** | 49 compressed original files, 709 chunk descriptors, zero reader errors; still no original native exact-decompressed-byte oracle |
| UT3 class-source differences | UT3 game-source addendum versus parser and identity projection | **MATCH / NO FORMAT OVERRIDE REQUIRED** | New Greed, Betrayal and Gold classes use the same UE3 wire tables; no class-name-based parser fork introduced |
| Source-gated import identity and public bit | `CatalogCompactIdentityEnricher`, `PdoUe3VerifyImportProjectionResolver` | **MATCH within prior accepted evidence** | January-2008 UT3 profile retains correct root/class/outer/public tests; later UDK remaps are not retroactively substituted |
| Known physical provider and mount-dependent resolution | UE3 provider selection, `Uedb5ClassicDependencyResolver` | **SOURCE-SAFE, NOT TOTAL RUNTIME EQUIVALENCE** | Selected physical linkers respected when grounded; unknown mount order remains **unresolved**, not invented as missing/resolved |
| Existing persistence and website paths | UT3 source snapshot / dependency publisher / current website source policy | **PREVIOUSLY ACCEPTED** | Six UE3 engine-specific contract rows show PASS; live PRD rollout previously recorded as 6,722 verified and policy-current; not rerun for this documentation review |

## General UE3 reader scope — gaps that must remain explicit

1. **Early UE3 family (225–471): NOT IMPLEMENTED by the current generic later-family reader.** The source-only engine document describes the early serialization family, but `EpicUE3PackageReader` intentionally refuses versions below 491. This is a **coverage gap**, not a demonstrated defect on UT3 v512. The undocumented 472–490 interval is correctly rejected rather than guessed.
2. **Later Xbox 360 LZX: KNOWN FAILURE on original packages.** Genuine UDK v813/0 `DM-Deck_LOC_INT.xxx` and UDKUltimate v820/0 `UDK-UDKUltimate_LOC_INT.xxx` fail the current raw PHP LZX decoder. Later engine source uses Microsoft's Xbox XMem LZX codec plus a buffer-padding convention; the exact PHP mismatch is not yet proven. Do not claim cross-platform UE3 LZX certification or change parameters without a native/known-byte oracle. This does **not** invalidate UT3 v512/LZO.
3. **Export-indexed DependsMap: source-described but not decoded as a complete auxiliary table by the primary PHP UE3 reader.** `DependsOffset` is preserved. Existing UnrealDB metadata/dependency operations use the imported-object tables and source-gated VerifyImport, so this omission does not establish an incorrect current UT3 dependency outcome. It does limit claims of *full general UE3-file-format coverage*.
4. **Later UE3 auxiliary maps and class payloads: PARTIAL/OUT OF CURRENT PRODUCT SCOPE.** The reader covers version-gated summary offsets and texture-allocation descriptors, but does not claim exhaustive materialization of later import/export GUID directories, thumbnail content, and class-dependent object/bulk-data decoders. UT3 v512 lacks the later header insertions; payload spans are preserved rather than incorrectly interpreted as universally tagged properties.
5. **Original runtime mount order and native fallbacks: UNDETERMINED without runtime environment.** This must not be converted into false physical provider success or confirmed missing dependencies. Previous original source-conditioned selected-provider evidence and production v14 rollout remain their own provenance-specific acceptance.
6. **Whole release/modified UE3 variants:** v512 alone does not uniquely identify UT3, and the 89 Patch 5 cases do not certify all UE3 revisions, game-specific licensees, or third-party package modifications.

## Decision

- **No UT3 v512 reader correction or package rescan is justified by the updated game-source documents or the 89 original Patch 5 results.** Keep the present game-specific v512/0 source gate and raw metadata preservation.
- **Do not broaden this PASS to general UE3.** Record late Xbox LZX and the early package-family parser as separately bounded engine-profile gaps; introduce support only after an actual consuming game/path and an original-source/native differential make the work necessary.
- **Do not add new fixed-contract IDs or re-run unrelated accepted tests.** Existing evidence and the one new 89-file read-only expansion are sufficient for the current source-to-product comparison.
- The independent source-only format documents remain free of UnrealDB implementation terminology. This implementation review is stored separately.

## Reproducibility

- Full original archive/installed identity: `tests/evidence/ue3/ut3-patch5-original-package-hash-differential-20261105.json`, including all 390 patch payload hashes; all 89 package members were byte-identical.
- New original 89-file reader result and SHA pins: `tests/evidence/ue3/ue3-ut3-patch5-full-reader-conformance-20261105.json`.
- Prior independent seven-file field oracle and six operation cases: `tests/evidence/ue3/ue3-original-ut3-v512-all-six-metadata-codepaths-20261102.json`.
- Prior three source-boundary fixes: `tests/evidence/ue3/ue3-v512-spec-boundaries-20261105.json`.
- Original UDK/LZX negative evidence: `tests/evidence/ue3/ue3-udk-original-corpus-20261105.json`.
- Temporary read-only runner/results: `D:\Temp\UnrealDB\ue3\spec-review-20261105\verify-patch5-all89-reader.php` and `patch5-all89-reader-review.json`.
- No production PHP, original package, UEDB5 snapshot or PRD SQL modifications in this revision.
