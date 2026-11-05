# UE2 source-conformance review — 5 November 2026

## Decision and scope

**Result: source-aligned changes verified on DEV for the implemented header/name/import/export, source-selection, static dependency and snapshot paths.** This is **not** a claim that UnrealDB executes native UE2 asset serializers, that all private engine revisions are available, or that physical package search/mount order is recoverable from file bytes.

Audited against the independent UE2 format specification and game addenda for **Unreal II**, **Unreal Tournament 2003** and **Unreal Tournament 2004**, including the corrected January 2003 Unreal II tree. Source is the reference for behaviour, original packages are differential evidence, and existing UnrealDB code was the audited subject—not an authority for the specification.

Source anchors, SHA-256 of game Core linker:
- Unreal II December 2000 v69/0x7F: `BF306975BAB6819EF50B23E4B6B3ECE44C1D5001295A935E45B7180D0DC8994F`.
- Unreal II January 2003 v126/0: `1934E3EF1C4042E33D3AF82D68CD593DBA7635A173BA8435D3D9AEE18FD69A0B`.
- UT2003 v2107-source / package v120/0x1C: `41DF04B157A79219E37A9AC48E1F292BA352642C28B6CEA2A1BD753576F53FEE`.
- UT2004 v3186 / package v127/0x1D: `8211E91DF606390CEA9E3758E17884C3B41238C6F0195F90A40C55C5EC7252D7`.
- UT2004 v129 source variant / package v129/0x1D: `171B005D96292A420B64D746AE1669E35E6AC6C953D700A7688BF5CF560FC22F`.

## Implementation-path disposition

| Operation | Actual production path | Decision |
| --- | --- | --- |
| Reader ownership and UT2004 pre-v100 compatibility | `CatalogReaderResolver`, `CatalogUE2PackageReader`, `CatalogUE2LegacyCompatibilityPackageReader` | Verified; no UE1 parser invoked for UT2004 compatibility |
| Exact tag, split engine/licensee version, version-64 names, version-68 summary, heritage GUIDs and generations | `CatalogUE2PackageReader` | Verified; restrict these three games to `0x9E2A83C1`, not another game's alternate tag |
| Signed compact indexes versus fixed-width import/export outer, nonzero export size/offset | `CatalogUE2BinaryStream`, `CatalogUE2PackageReader` | Verified; added malformed name/object reference and positive payload-span bounds rejection |
| Signed ANSI/UTF-16 strings and genuine FName text | `CatalogUE2BinaryStream` | Corrected: every ANSI octet maps independently to its Unicode code point; no UTF-8/Windows-1252 guessing |
| Serialized raw names and context-filtered effective names | `Uedb5UE2TableSnapshotBuilder`, `CatalogLegacyNameMapPreprocessor`, `Uedb5ClassicDependencyResolver` | Verified; UT2004 v127 included in source-supported 63-character effective-name rule |
| Unreal II source selection | `Uedb5Unreal2SnapshotBuilder`, `Uedb5Ue2VerifyImportSourceProfile` | Corrected: v126/0 selects corrected **game** source; v69/0x7F admits December 2000 load branch; version/other-licensee cases remain bounded |
| Unreal II January 2003 non-public provider exports | `PdoUe2VerifyImportProjectionResolver` | Corrected: normal **non-forgiving** v126 lookup may retain matching non-public export; forgiving load would still report broken link. Distinct from v69, UT2003 and UT2004 |
| UT2004 v127/v128/v129 source attribution | `Uedb5Ut2004SnapshotBuilder`, `Uedb5Ue2VerifyImportSourceProfile` | Corrected: v127/0x1D source recognized; v129/0x1D emitted source recognized; observed v129/0x1B remains separately evidenced only for table/static import path |
| Original physical provider choice | `Uedb5ClassicDependencyResolver`, provider selector | Retained fail-closed behaviour when true runtime order is unavailable |
| Native property serializers, fonts, meshes, sound, level/particle payloads, QuickMD5 | No active UE2 catalogue payload reader | Not implemented and **not required** for current file/metadata/dependency/catalogue scope; not certified |

## Defects reproduced and corrected

1. UE2 FName outside NameMap accepted as empty identity. Original fixture `149470`, import name index changed to 63 of 35: **before accepted / after rejected**.
2. A Killing Floor-only `0x9E2A83C2` tag accepted by Unreal II reader. Original fixture with tag changed: **before accepted / after rejected**.
3. Bytes `C3 A9` interpreted as one UTF-8 character. **After** correction original byte-to-TCHAR semantics yield two characters, `Ã©`.
4. Invalid import outer +1 and out-of-range export ClassIndex -20: **before accepted / after rejected**; valid originals remain accepted.
5. Unreal II v126/0 was generic-source-attributed with **no** source-selected static import-verification profile. **After** correction its own source policy selects the January 2003 loader; the non-public match and alias/compatibility differences remain separate.
6. UT2004 game source v127/0x1D and source-emitted v129/0x1D were missing from exact source-policy selection. **After** correction both are selectable; v127 name preprocessing is explicitly enabled.

## Original-byte and downstream verification

Retained evidence: `D:\Temp\UnrealDB\UE2\original-wire\manifest.json`; `D:\Temp\UnrealDB\UE2\original-wire\ue2-independent-original-raw-tables-all-rows.json`; `tests/evidence/ue2/ue2-real-original-metadata-and-dependency-parity-20261102.json`. Original SHA-256 identities unchanged.

Executed after changes, all on **DEV**:
- `D:\Temp\UnrealDB\UE2\review-ue2-metadata-gates-20261105.php` — valid source fixture plus three negative/encoding checks and version-policy selections.
- `D:\Temp\UnrealDB\UE2\review-ue2-object-index-gates-20261105.php` — two malformed reference cases rejected.
- `D:\Temp\UnrealDB\UE2\review-ue2-original-and-verifyimport-20261105.php` — **10/10** SHA-pinned original files, expected tables/version/licensee, all supported game policy selections, public/private tests for four source profiles and wrong-root rejection.
- `D:\Temp\UnrealDB\UE2\review-ue2-ut2004-v127-dependency-20261105.php` — genuine v127/0x1D, effective 63-character limit and 4/4 static import rows.
- `tests/tools/check_reader_routes.php` — UE1–UE5 ownership, cross-generation dispatch and UE1/UE2 isolation pass.
- `tests/tools/verify_ue2_ut2004_independence.php` — genuine legacy-wire compatibility, 3 import rows, UE2 parser ownership and unchanged snapshot pass.
- All changed PHP files passed `php -l`; `git diff --check` contained no whitespace errors.

**Historical-test limitation:** `tests/tools/verify_reader_silo_real_paths.php` cannot run at its pinned historical path because `C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.u` is not present. This is a missing historical fixture, not a failing UE2 runtime comparison; frozen evidence was not rewritten.

## Operational boundaries and next deployment obligations

No original package was modified, no original UEDB5 storage was backed up or edited, and no production SQL update was run by these checks. New policy names and corrected ANSI handling affect **newly generated** metadata. Pre-existing UEDB5 snapshots keep their earlier provenance and original interpreted names until a **scoped source-based regeneration/republication**. Do not claim the full live corpus has been republished or that every old name has been repaired merely because code deploys successfully.

For previously indexed files affected by source-policy changes (notably Unreal II v126/0; Unreal II v69/0x7F; UT2004 v127/0x1D), identify exact candidates and regenerate/re-publish their derived metadata/dependencies using original files under the existing durable import workflow. Source-independent version/compatibility documents remain the specification. No requirement to rescan unrelated engines or files follows from these corrections.

**Remaining external-state gaps:** native package mount order, runtime classes/native/transient objects and load flags, exact game-private later source, and full class-specific payload parsing. These cannot be marked verified from package table evidence. The v126 static private-export acceptance represents the default non-forgiving path, not a simulation of arbitrary `LOAD_Forgiving` sessions.
