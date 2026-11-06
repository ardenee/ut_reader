# UE2 format specification and UnrealDB reader gap register — 6 November 2026

## Supported-source UEDB5 production audit — no stale attribution detected (6 November)

**Read-only PRD group postflight:** all **78,773** verified UE2 game-file rows have a registered UEDB5 `ue_uedb5_files` row. For the **66,796** files in explicitly source-authorized game/version/licensee cohorts, the stored source-loader policy matches an authorized Unreal II, UT2003 or UT2004 loader selection with **zero mismatches**; UT2004 v127/29 is correctly admitted under v127 **or** the source-identical v128 loader policy. The remaining **11,977** verified files are intentionally outside the exact native `VerifyImport` source-profile coverage, not missing UEDB5 registration.

Evidence and source SQL SHA: `tests/evidence/ue2/ue2-production-supported-uedb5-source-policy-postflight-20261106.json`. The test checks metadata registration and stored source-policy **not each physical UEDB5 container hash, every serialized section schema or native per-import result**. Consequently, do not manufacture a full historical native parity PASS. No further source-policy restaging/rescanning is required *for the supported cohorts*.

---

## Final bounded v118 original content test: 12 providers, actual native member mismatch (6 November)

After the initial v118 `SuperWeapons.u` native load stopped on missing `Inventory` and then `Postal2Game`, a **single bounded PRD read-only source scan** identified all **12** v118/licensee2 `*.u` packages whose original acquisition provenance has the same `t/` prefix. Each original (including `FPSGame`, `Inventory`, `Postal2Game`, `Shell`, `MultiGame` and the other related classes) was copied **only** to the DEV test directory, with its exact stored byte count and MD5 checked. The complete manifest, catalogue file IDs and SHA-256 of all twelve copies are pinned in `tests/evidence/ue2/ue2-original-native-v121-v118-loader-observations-20261106.json`.

The authenticated UT2004 patch-3369 **v128.29 native loader** then read these available originals using a **test-only ini search path**. It advanced beyond `Inventory`, `Postal2Game` and `FPSGame`, but reported missing **`Engine.HUD.TextMessages` (StrProperty), `Engine.Actor.PostLoadGame` (Function), `Engine.HUD.MessageLife` (FloatProperty), `Engine.GameInfo.bIsSingleplayer` (BoolProperty)** and ultimately an unmounted `MiscSounds` package. This is **direct original runtime evidence of a native class/member mismatch for this v118 content under the tested UT2004 loader/context**, beyond mere absent table fields. It **does not** establish that all v118 files fail, that the stored game ownership is necessarily incorrect, or that UnrealDB must parse any of those native bodies.

**Decision:** retain the narrower exact loader-source `VerifyImport` gates, rather than extending them to all v118/v121 based on a single v121 package loading. Use original source and genuine native per-import outcomes before any bulk policy change. Existing UE2 table/document parity and completed source-policy cutovers remain valid. No extra scans, production writes, UEDB5 backups or deployments were made.

---

## Additional authenticated UT2004 native test — original v121 accepted, original v118 dependency-limited (6 November)

**New retained original executable evidence:** Installed `H:\Epic Games\UT2004\System\Core.dll`, `Engine.dll` and `UCC.exe` are SHA-256 identical to archived authentic patch **3369**. Source paths and hashes, exact originals and native logs are pinned in `tests/evidence/ue2/ue2-original-native-v121-v118-loader-observations-20261106.json`. CDB adjusted only the Unicode *commandlet argument string in the running process*, because the original Windows commandlet forwards the command name into `Main`; neither the executable, DLLs, canonical game source nor package bytes were patched. No production SQL, UEDB5 or dependency records were written.

- Genuine **UT2004 game #5** `spaceship.u` (#1282344, v121/licensee29, 30 imports, SHA-256 `408f1f148d742dff782835dfd97e32c9badf13704dcf9ae28078c64d0d57781f`) was accepted by the original compiled **v128/licensee29** `UPackageFlagCommandlet::Main -> LoadPackage` path with **zero native errors and zero warnings**, under the tested installation/mount context. The retained UCC log and debugger log have distinct SHA-256 pins. This is genuine native backward-*load* evidence for this original—not proof every import was independently `VerifyImport`-traced or that all 3,855 catalogued v121 files have identical producers/dependencies.
- Original `SuperWeapons.u` (#1289524, v118/licensee2, 245 imports) initially failed at missing `Inventory`. An exact SHA-256-preserved original `Inventory.u` (#1360855, byte-identical #149396; MD5 `17936307f4ec6675d46911d8cc8c2ce8`) was read from PRD and added to an **isolated test-only search path** via a copied INI. The next native failure was missing **`Postal2Game`**, inherited through `Inventory`. This establishes an unsatisfied external mod dependency, *not* a proven v118 format error, incorrect game ownership, or a full compatible loader outcome. The original `SuperWeapons.u` path is an **UT2004 Mutators** archive; do not automatically reassign it based on an imported third-party root.
- DEV PRD read-only coverage remains **326/808** Unreal II, **889/5,082** UT2003, **65,581/72,883** UT2004 under accepted exact game-source profiles. The new single-file native observation **does not authorize globally widening** `Uedb5Ue2VerifyImportSourceProfile` or changing 3,855 historical v121 snapshot policies. The first-party `VerifyImport` code path, exact runtime mount state and load flags still need source/native acceptance per supported cohort. Existing v69 and v127/v128 repairs remain completed; **no further full rescan** is implied.

**Scope limit:** UnrealDB continues to handle only headers, names, import/export tables and dependency metadata. Tagged native object payloads and optional QuickMD5 are not implemented current catalogue operations; they are not compliance blockers for those paths.

## Additional UT2004 source-loader compatibility closure (6 November, scoped to original v127)

Read-only PRD source-policy inspection found **10,312 verified UT2004 package v127/licensee29** records staged with `source_policy=ue2-ut2004-v3369-package-v128` and only **3** staged with the v127 policy. The v127 raw bytes and UEDB5 table layout are already independently certified, but `Uedb5Ue2VerifyImportSourceProfile::fromSnapshot` rejected those 10,312 because it incorrectly required the *on-disk producer version* 127 to imply that the *chosen package loader* must have version 127. The two source revisions must remain distinct: source-loader policy is not a serialized package writer ID.

**New original source differential, not an extrapolation:** the complete UT2004 v3186/v127 and v3369/v128 Epic `ULinkerLoad::VerifyImport` bodies are token-identical after removing whitespace/comments (999 tokens). The v129 source differs from them only by two `*` dereferences used when formatting exception strings (`LocalizeError`), with the same matching/visibility logic. None of the three `VerifyImport` implementations branches on `ArVer` or `ArLicenseeVer`. Source code and SHA-256 are pinned by `tests/tools/verify_ut2004_v127_v128_core_verifyimport_source.py` and `tests/evidence/ue2/ut2004-v127-v128-loader-source-equivalence-20261106.json`.

The UE2-only source-profile gate now allows an exact **UT2004 v127/licensee29** package to be handled under the real v128 loader policy **or** its own source-v127 policy. It never reassigns the package's original writer identity. Other engines and games are unchanged; v118/licensee2 and v121/licensee29 remain **not authorized** by this correction, despite having independent table-format proof. A real v127 PRD original (`2K4Fonts.utx`, #111461, SHA-256 `04f292545b011359b071d781da35b531bfa22cb5afeceb395bb6e248d9680c62`, 77 Names/4 Imports/57 Exports) now passes the selected-source profile through its unchanged existing v128-labelled UEDB5 snapshot. The new regression covers **14 cases** including wrong game, wrong licensee, wrong schema, mismatched loader policy, and unproven older versions. `tests/tools/verify_ut2004_v127_snapshot_v128_loader_parity.php` verifies it read-only against PRD.

**Production state — completed 6 November (user-provided PRD output):** `publish-ue2-ut2004-v127-v128-loader-compat.php --apply` processed **all 10,312 scoped UT2004 v127/lic29 UEDB5 snapshots**, stopped at source file ID **1363134**, reported `remaining_after_this_batch=0`, and did **zero original file rescans / zero UEDB5 backups**. The user then ran `rebuild-game-catalog-stats.php --game-id=5`: **72,883 verified files, 14 failed, 1 duplicate, 10,575,176 dependency rows**. The earlier unpublished state is superseded. This was targeted existing-snapshot Pass2, not a full game rescan. Source-native UT2004 v118/v121 loader validation remains a separate open investigation; a disposable commandlet invocation that failed before package loading is **not** accepted as evidence.

---

## Purpose and authority separation

This is a **comparison and remediation report**, intentionally kept OUTSIDE the language-independent binary-format specifications. The source-derived canonical format documents are:

1. `specs/ue2-package-binary-format.md` — shared byte schema for the classic UE2 package summary, Name, Import and Export tables, and export-payload boundaries.
2. `specs/unreal2-package-format-addendum.md` — independent Unreal II game revisions, structural deltas and documented version/serializer boundaries.
3. `specs/ut2003-package-format-addendum.md` — independent UT2003 game revisions and table types.
4. `specs/ut2004-package-format-addendum.md` — UT2004 127/128/129 revisions, legacy compatibility and format-specific differences.
5. `specs/ue2-warfare-package-format-addendum.md` — generic Unreal Warfare UE2.5 source, not inherited as a game policy.

The earlier four mixed documents containing source-code paths, implementation operations, and catalogue conformance remarks are preserved verbatim under `archive/ue2-mixed-format-implementation-notes-20261106/`; their original filenames in `specs/` now act as redirects. **None of the new canonical format documents names UnrealDB, UEDB5, its readers, PHP or an administrative page as part of the binary format.** Runtime package search/mount and import verification remain separate loader-behaviour documents.

## Existing local evidence and first-party authority

- Game source: `L:\Source\Games\Unreal II` (December 2000 and January 2003 Core), `L:\Source\Games\UT2003` (v2107 Core), and `L:\Source\Games\UT2004` (v3186/v3369/later v129 Core).
- Generic UE2.5: `L:\Source\Engine\UE2\Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`.
- Real game executables and compiled `Core.dll` / `Engine.dll` for both UT2003 and UT2004 are available in `H:\Epic Games\UT2003\System` and `H:\Epic Games\UT2004\System`.
- Original distribution media: `M:\iso\Unreal Games` contains Unreal II and multiple UT2003/UT2004 disc archives, including different releases and later editions.
- Prior original-file differential evidence: `D:\Temp\UnrealDB\UE2\original-wire`, 10 SHA-256 pinned genuine package bytes and detailed raw-table parity, plus `tests/evidence/ue2/ue2-real-original-metadata-and-dependency-parity-20261102.json`.
- Existing native/source parity, website and cross-engine traces: `D:\Temp\UnrealDB\UE2`, `D:\Temp\UnrealDB\process-tracing`, `tests/evidence/ue2`.
- Available investigation methods include CDB/WinDbg, MSVC/link.exe, original game executables, source-level native fixtures, Doxygen/Graphviz, Xdebug, REA, exact SHA-256 and archive/ISO inspection. These methods are evidence opportunities, not claims that a missing revision has already been authenticated.

## Independently verified package bytes (current audit)

**One new, standalone Python decoder**: `tests/tools/verify_ue2_source_binary_layout_independent.py`. It implements only the package wire schema from first-party C++ serializers, not UnrealDB code; it independently validates tag, file version, name encoding, exact fixed/compact field ordering, v64/v68 summary changes, table seeks, signed references, positive export spans, full-file bounds and original SHA-256. It runs six additional malformed-input rejection probes **in memory** and never modifies the original file.

It passed **10 retained genuine originals plus five previously untested, nonempty version/licensee cohorts** from PRD. The latter are:

| Original file ID | Original game | Version/licensee | Names | Imports | Exports | SHA-256 |
|---:|---|---|---:|---:|---:|---|
| 1277117 | UT2003 | 121/29 | 17 | 5 | 2 | `55ec6c7e232078e30c6bc2fea8b5bebfc8007dcbb59acc3fcb150b8a3fbc1a0b` |
| 1282344 | UT2004 | 121/29 | 58 | 30 | 23 | `408f1f148d742dff782835dfd97e32c9badf13704dcf9ae28078c64d0d57781f` |
| 1289524 | UT2004 | 118/2 | 513 | 245 | 276 | `4c7e622a8fad487c440941ae8255f322e179472751eb09e4bff7f5bcf8915a84` |
| 111284 | Unreal II | 110/2481 | 111 | 14 | 25 | `d71c6fea53cd5951ab70baeabf6e945593a1eecf44d43e9d96672290f3bb4462` |
| 111218 | Unreal II | 110/2609 | 30 | 12 | 16 | `3860383351456e66ef1ac88093a0bdd26345c3e0a16cd8ab9e28c6a14556344b` |

The independent output is `tests/evidence/ue2/ue2-binary-format-15-originals-and-negative-probes-20261106.json`. The initial **UnrealDB reader differential** `tests/tools/verify_ue2_reader_against_independent_wire_evidence.php` passed all five additional originals on versions, licensees and table counts. A subsequent full-field differential extends that acceptance beyond counts: **all 729 Name entries, 306 Import entries and 342 Export entries**, across those five originals, now match on **every serialized field and exact individual entry byte offset** (1,377 rows; zero discrepancies). It uses independent source-derived Python canonical whole-table field digests, compared against actual UE2 PHP reader rows, while independently verifying each original file SHA-256. Scripts: `tests/tools/verify_ue2_independent_full_table_fields.py` and `tests/tools/verify_ue2_original_full_table_fields.php`. New separate SHA-pinned evidence (original accepted evidence unchanged): `tests/evidence/ue2/ue2-original-version-cohorts-full-field-differential-20261106.json`. Earlier independent original-byte evidence covers all serialized fields on the first 10 originals. **Neither differential claims complete native export-class payload decoding or engine runtime object loading.**

### Why the earlier numbers were misleading

A binary's **emitted** package version is not its **minimum loadable** package version. The UT2004 v129 linker source declares minimum v60, and its `VerifyImport` body does not itself branch on `ArVer` or `ArLicenseeVer`. The preceding coverage metric for UnrealDB's strict, exact-emitted-version profiles is **not the full set of versions this newer game loader can read**. It measures current provenance gating, not binary-format compatibility. A responsible implementation must distinguish:
- a file's **writer/source revision**, which cannot always be proved from the header alone;
- a game binary's **loader-compatible** version range, established through that binary and serialization/flag branches;
- class-specific runtime/native object loading;
- **offline table-format decoding**, which is already independently proven for the five additional populations above.

No broad expansion of `VerifyImport` profiles has been authorized merely by the table decoder succeeding; do not jump from file-format parity to game-native-object parity.

For a repeatable, **read-only** view of the production inventory rather than a stale pasted number, run `php catalog/bin/diagnose-ue2-source-profile-coverage.php --game-id=5 --top=20` on PRD, or add `--db-host=192.168.0.55` on DEV. The latest PRD source-policy diagnostic reports **Unreal II 326/808**, **UT2003 889/5,082**, **UT2004 65,581/72,883** current explicit game profiles. It displays version/licensee groups separately from binary parsing acceptance; it writes no SQL, UEDB5 or files.

## Format-document gap matrix

| Area | Outcome | Evidence or remaining work |
|---|---|---|
| Shared UE2 header 36-byte layout and split packed version | **Closed** | Complete local Core serializers; independent raw original pass |
| v64 string/name entry distinction; signed ANSI/UTF-16LE lengths | **Closed for structural layout** | Core serializers; 15 originals; existing source-ANSI and malformed negative tests |
| v68 GUID/generation versus heritage | **Closed for structural layout** | Core serializers; existing v61 heritage sample and later GUID samples |
| Import/Export compact versus fixed signed index widths | **Closed** | Core serializers; independent parser; actual legacy source-reader comparisons |
| `SerialSize != 0` conditional offset (including signed negative size) | **Closed for on-disk field presence** | Core source and prior negative-size regression; bounded positive-byte span |
| UT2004 v127/v128/v129 table-width compatibility | **Closed for generic tables** | Three game source revisions; no invented package-wide 64-bit fields |
| UT2003 v121/29 and UT2004 v121/29/v118/2 table bytes | **Closed for all serialized table fields on selected originals** | 3 genuine SHA-pinned files; complete Name/Import/Export entries and byte offsets compared against independent decoder |
| Unreal II v110/lic2481 and v110/lic2609 table bytes | **Closed for all serialized table fields on selected originals** | 2 original files; full rows and offsets source-derived and cross-checked; native object-body semantics remain unproven |
| Full on-disk **class-specific export body** serialization | **Open / deliberately separate** | Traverse native class `Serialize`/property/version gates for object classes in the actual datasets, capture runnable format sub-specifications, test against original bytes |
| Full UT2003 **post-v120 historical game-native implementation**, v121/29 | **Open** | Retrieve and hash original Core.dll/UCC from period-correct game media/update; native debugger or UE commandlet differential; do not infer from UT2004 |
| Unreal II v110/lic2481/2609 exact producer and native object-body branches | **Open** | Match to original distribution executable from ISOs; trace compiled source-specific serializers; distinguish Unreal II/XMP variants |
| UT2004 older package-version **compatibility under the authenticated v128 loader**, not historical producer | **One original v121/lic29 native LoadPackage PASS; v118/lic2 tested but BLOCKED by missing Postal2Game; cohort-wide VerifyImport remains OPEN** | Retained original patch-3369 compiled native/UCC logs establish one v121 backward load; add exact per-entry import/mount evidence for both cohorts before any global source-profile expansion |
| Legacy UE1-format packages opened by UT2004 | **Partly independently verified** | Existing isolated UE2-owned historical-wire reader; v99 original package; pre-v50 source semantics remain separate UE1-format rules |
| `.uz`/`.uz2` archive wrappers and other compression layers | **Outside this raw package format** | Require separate wrapper/codec source and raw-payload comparison if feature is needed |
| 100% all possible C++ native object payloads and every community fork | **Not established** | Cannot truthfully certify this from table/source headers and a finite original set; build finite used-class/version matrix first |

The core UE2 **package container and tables** are now specified independently of any catalogue. Complete all-native-class payload format is **not** synonymous with complete table-format specification.

## UnrealDB-specific path and implementation gap matrix

| Actual UE2 catalogue operation | State | Next bounded action |
|---|---|---|
| Detect package tag, split version/licensee | Verified in `CatalogUE2PackageReader` | Keep negative/truncated input gates |
| Decode Name/Import/Export tables, signed compact and fixed fields | **15 originals; all fields on first 10 retained, plus 1,377 serialized entries across five new cohorts compared field-for-field** | Keep independently derived original SHA/field digests pinned and run only when code/source inputs change |
| Source name and context transformations | Source-backed for audited name variants | Preserve raw names and effective names; test custom-licensee/overlong names only when source evidence requires different handling |
| UE2-owned UT2004 legacy package format | Existing isolated reader and genuine v99 sample | Keep the UE1-wire compatibility parser isolated from UE1 engine module |
| Source policy attribution by game/version/licensee | 5 Nov/6 Nov corrections done; Unreal II v60–69 Pass 1/2 complete | Separate **writer identity** from **source-loader compatibility**; avoid using emitted version as an unjustified hard minimum |
| `VerifyImport` selected-source linker, name/class/outer/public/private semantics | Existing v69, Unreal II v126, UT2003 v120, UT2004 v127/v128/v129 profiles; exact version gates are restrictive | Verify actual newer-loader compatibility against binary/native tests and then extend only the source-justified consumer profiles |
| Original runtime package mount/search order | Inherently environment-dependent | Show offline compatible alternative providers separately; do not invent a single runtime-picked provider |
| Dashboard resolved/unresolved values | v15 source-policy completion and fresh game statistics; many imports unresolved by conservative mount context | Improve offline provider evidence display without relabelling runtime-unselected alternatives as `resolved` |
| Native class-specific payload parsing | Not used by the current header/table/dependency pages | Do not perform costly payload rescans merely to satisfy unrelated class-format research |
| Job and database migration | Current Unreal II **808/808**, UT2003 **5,082/5,082**, UT2004 **72,883/72,883** under UE2 dependency v15 | Do **not** run full resync for these documentation-only changes |

## Production closure for the previous two-file repair

The user applied `publish-ue2-unreal2-legacy-v69-scoped.ps1 -Apply` on Lelly-pc. A separate DEV read-only diagnostic against the real PRD original bytes and current UEDB5 state then returned:

- `Detail.utx` **#149492** — **current** December 2000 v69 source policy.
- `ShaneChurch.utx` **#1295446** — **current** December 2000 v69 source policy.
- `needs_resnapshot=0`, `current=2`, `dependency_published_current=true`, `dashboard_stats_current=true`.
- PRD latest game #2 rebuilt statistics: 808 verified, 0 failed, 1 duplicate, 74,556 dependency rows. Package Coverage rebuilt 84 packages, 70 cached, 14 pruned.

**No pending two-file PRD repair** remains. The new binary-format documentation and independent tests do not alter existing UEDB5 records or require any production rescan.

## Investigation order for the remaining nontrivial gaps

1. **Match relevant compiled producer/loader builds** by original EXE/DLL SHA-256 and version fingerprints from the installed games and discs. Do not assume an ISO distribution's label proves the contained executable version.
2. **Extract and trace only UnrealDB-used source operations**, then map version-conditioned field serializers. For complete native-object file specifications, separately extend to each actually present export class and type.
3. For unresolved v121, v118 and v110 branches, use isolated native tests with original hashes and a reproducible package sample. Compare exact summary/table reads and import flags with source-based local projections; record outcomes including runtime class/mount context.
4. Only when source/native checks demonstrate compatible loader semantics should the per-game `Uedb5Ue2VerifyImportSourceProfile` gates be widened. Add negative mixed-branch tests and targeted dependency Pass 2 rather than a full package rescan.
5. Maintain a separately labelled **offline candidate-compatible** comparison, never a fabricated engine-selected runtime resolve. This can make the dashboard more useful without corrupting Epic loader semantics.

This ledger lists verified work and open work separately. No all-versions or all-native-payload completion claim is made.
