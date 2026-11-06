# UE2 format specification and UnrealDB reader gap register — 6 November 2026

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

The independent output is `tests/evidence/ue2/ue2-binary-format-15-originals-and-negative-probes-20261106.json`. **Existing UnrealDB reader differential** `tests/tools/verify_ue2_reader_against_independent_wire_evidence.php` also passes on all five additional originals, with exactly matching parsed versions, licensee versions and table counts. Earlier independent evidence covers all serialized source fields on the first 10 originals; this new check adds original cohorts and parser bounds, **not** a claim of new full native object-body differential testing.

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
| UT2003 v121/29 and UT2004 v121/29/v118/2 table bytes | **Closed on sampled, nonempty originals** | 3 additional genuine files with 5/30/245 Imports and nonzero Exports; read-only PHP differential |
| Unreal II v110/lic2481 and v110/lic2609 table bytes | **Closed on sampled, nonempty originals** | 2 additional genuine files; documented raw-table compatibility only |
| Full on-disk **class-specific export body** serialization | **Open / deliberately separate** | Traverse native class `Serialize`/property/version gates for object classes in the actual datasets, capture runnable format sub-specifications, test against original bytes |
| Full UT2003 **post-v120 historical game-native implementation**, v121/29 | **Open** | Retrieve and hash original Core.dll/UCC from period-correct game media/update; native debugger or UE commandlet differential; do not infer from UT2004 |
| Unreal II v110/lic2481/2609 exact producer and native object-body branches | **Open** | Match to original distribution executable from ISOs; trace compiled source-specific serializers; distinguish Unreal II/XMP variants |
| UT2004 older package-version **compatibility under v129 loader**, not historical producer | **Source-compatible in principle; native differential outstanding** | Confirm per-version NameMap/payload preprocessing and VerifyImport, use disposable compiled UT2004 game fixture to load real v118/v121 packages, record native import outcomes before widening profile gates |
| Legacy UE1-format packages opened by UT2004 | **Partly independently verified** | Existing isolated UE2-owned historical-wire reader; v99 original package; pre-v50 source semantics remain separate UE1-format rules |
| `.uz`/`.uz2` archive wrappers and other compression layers | **Outside this raw package format** | Require separate wrapper/codec source and raw-payload comparison if feature is needed |
| 100% all possible C++ native object payloads and every community fork | **Not established** | Cannot truthfully certify this from table/source headers and a finite original set; build finite used-class/version matrix first |

The core UE2 **package container and tables** are now specified independently of any catalogue. Complete all-native-class payload format is **not** synonymous with complete table-format specification.

## UnrealDB-specific path and implementation gap matrix

| Actual UE2 catalogue operation | State | Next bounded action |
|---|---|---|
| Detect package tag, split version/licensee | Verified in `CatalogUE2PackageReader` | Keep negative/truncated input gates |
| Decode Name/Import/Export tables, signed compact and fixed fields | Verified on prior 10 original files and 5 additional version cohorts | Retain the independent parser as a regression and compare source-/original-hashed fixtures |
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
