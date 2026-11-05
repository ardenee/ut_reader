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
| Compression chunk metadata and zlib/LZO/LZX reconstruction | UE3 parser and UDK source comparison | **PARTIAL**: six genuine installed UT3 LZO packages parse; later UDK Xbox LZX examples fail; independent original-native differential remains open |
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
- **Compression:** the supplemental UDK/installed-UT3 inspection now demonstrates six real compressed UT3 LZO positives (see below). An independent original-native decompression-byte differential is still absent. Later UDK Xbox LZX input demonstrably fails the current PHP path and must not be claimed supported.
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

## Supplemental source comparison: UDK, UDKUltimate, installed UT3 (2026-11-05)

This supplementary inspection covers the four newly identified UDK/source-and-tool roots and `H:\Epic Games\Unreal Tournament 3`. It does **not** change the original-source priority for UT3: the January 2008 Epic UE3 loader and the game-specific UT3 version-512/licensee-0 evidence remain authoritative. UDKUltimate is a later redistributed/forked source tree, not proof of the byte-for-byte UT3 2008 compiled code.

### Corpus provenance

- `L:\Source\Engine\UDK` contains one Xbox 360 sample build, **engine version 8171, changelist 833356, April 5, 2011**, with XEX and PDB artifacts. Its contents are effectively mirrored under `L:\Source\Engine\UE3\UDK Game Sample (833356 2011-04-05)`: 58 matching relative file paths and lengths, with one additional `build_info.txt` under the UE3-path copy. The sample map `DM-Deck_LOC_INT.xxx` has identical SHA-256 `234d20763fa7c026a2b4fc36191ed3cc52170fd439ef9465624bb0aaf2e9af0c` in both copies.
- `L:\Source\Engine\UE3\Unreal Engine [v3.0] UDKUltimate [05-11-17]\UDKUltimate\Development\Src\Core` contains complete later UE3 C++ linker, name, version and compression routines alongside compiled assets. The directory holds a mixture of version-820 packages and other historical formats; it is **not** a UT3 build.
- `L:\Source\Engine\UE3\Unreal Engine [v3.0] Tools [00-00-2010]` contains model viewers, decompilers and console tooling, but no corresponding native UE3 Core linker source suitable as separate format authority.
- The installed UT3 executable `H:\Epic Games\Unreal Tournament 3\Binaries\UT3.exe` has SHA-256 `097518615da0fe4ed73f104186121a58cd69ff30be1007e770fa2df0473cd70f`; the PE file-version fields were blank. No native UT3 process trace was performed.

### Installed UT3 header and LZO evidence

A read-only header census enumerated **955** `.u`, `.upk` and `.ut3` files with valid UE3 magic in the installed game, all **package version 512 and licensee 0**. There were **500 uncompressed** files and **455 with compression algorithm LZO (flag 2)**. The former uncertainty about whether any genuine compressed UT3 packages were available is therefore resolved.

The exact production `CatalogUE3PackageReader` accepted these six independently installed, genuinely LZO-compressed UT3 files (no file copies altered):

| Real original package | SHA-256 | Compression chunks | Names / imports / exports | Reader |
|---|---|---:|---|---|
| Core.u | `1384474da4bed048b1b3df3bdad0d2b9e0a1aa4d6eeadca2dde894b9f9171993` | 1 | 639 / 20 / 1,228 | accepted |
| Editor.u | `23c59f0924a06aa2ae39c9ace19e27e7432cc4561e514e04d4610f05fcaa329e` | 1 | 447 / 54 / 430 | accepted |
| GameFramework.u | `f9cd3394916a5c140d106245fd6a7e2974a7fb888ce03ac3bcb973c2607dee82` | 1 | 240 / 103 / 166 | accepted |
| IpDrv.u | `4515822a2827743cbed81100b6fdbdd2b4e3c8f668c7f00af297727edd0cb390` | 1 | 158 / 27 / 189 | accepted |
| Engine.u | `5f08a77ea18fc4a84cafd6832066231ad5c230b034d008f964bf4b5047cb8983` | 8 | 15,079 / 145 / 25,138 | accepted |
| Startup_CZE.upk | `9631438737c4aaf228632c1a0322f87993507438faa061e0838313c97706054a` | 18 | 2,293 / 649 / 6,750 | accepted |

All six report `logicalDecompressed=true`, no reader validation issues, and exact match of decoded table counts to their package summary counts. **These observations do not constitute an independent original compiled-engine decompression byte differential.**

### Versioned native implementation differences

1. **Summary/export wire layout:** January 2008 UE3 and UDKUltimate native routines agree on the order of common header and export fields. The later code adds version-gated summary fields at 516 (additional packages), 584 (thumbnail table), 623 (crosslevel GUIDs), and 767 (texture allocation); 543 removes legacy component-map serialization. This supports the v512 schema but not blanket parity for every later revision.
2. **Name context filtering differs:** January 2008 `ULinkerLoad::SerializeNameMap` uses serialized name flags to mask nonmatching client/server/editor contexts. UDKUltimate `SerializeNameMap` explicitly ignores those flags at runtime. Its `FNameEntry` serializer still preserves a serialized flags field, using a zero dummy when name flags are compiled out. **Do not replace UT3's early effective-name-map rule with later UDK runtime behaviour.**
3. **Class identity differs:** January 2008 `GetExportClassPackage` requires the class import's parent to be another import; the later UDK method additionally handles export parents. UnrealDB correctly isolates this distinction through its UT3 v512 source-policy path.
4. **Class remapping differs:** later `ULinkerLoad::RemapClasses` contains a pre-536 Prefab sequence compatibility conversion. That method is not present in the examined January 2008 UT3-era linker, so it must **not** be applied to UT3 metadata merely because a package reports version 512.
5. **UE3 dependency sections:** later `SerializeDependsMap` shows one dependent-index array per export, but skips reading it in seek-free runtime contexts. The original table-only UnrealDB contract does not consume export-indexed DependsMap. Its existence is not a requirement to deserialize object payloads.

### New evidence of incomplete **later-UDK LZX** compatibility

The installed 2011 sample includes seven normal v813/licensee-0 packages and eleven other `.xxx` records whose header does not fit the same conventional package profile. The later UDKUltimate tree contains a large v820 family and other versions.

Two original, unmodified Xbox 360 LZX packages fail the **generic** UnrealDB UE3 reader (the reader correctly reports errors and refuses completed table acceptance):

| Genuine later-UDK sample | Version / codec | Observed failure |
|---|---|---|
| `UDK\...\CookedXbox360\DM-Deck_LOC_INT.xxx` (SHA-256 `234d20763fa7c026a2b4fc36191ed3cc52170fd439ef9465624bb0aaf2e9af0c`) | 813 / LZX | `Invalid LZX block type=4` |
| `UDKUltimate\...\CookedXbox360\UDK-UDKUltimate_LOC_INT.xxx` (SHA-256 `579071c90a63196e31bdaeca6b9b04a9b137839f1aa6f61a3e36de9c2313dfe5`) | 820 / LZX | `Invalid or incomplete LZX Huffman table` |

An original version-820 PS3 `ENVYENTRY.XXX` with ZLIB compression (SHA-256 `7140cbd4e4502668902ac11cbae47240cbb57ecc0cbdbd0847c08029d296e8e2`) parses successfully (60 names, 18 imports, 18 exports). A plain v787 UDKUltimate package also parses.

The later native `appUncompressMemoryLZX` invokes Microsoft `XMemCreateDecompressionContext`/`XMemDecompress` using the Xbox LZX codec and accounts for a four-byte padding convention. The current PHP implementation uses its independent raw LZX decoder with a 17-bit window. **The precise reason for the mismatch is not yet proved**, and blindly removing padding or changing the window would be unsupported. Keep v813/v820 Xbox LZX packages **unverified/unsupported** rather than promising full generic UE3 491–867 support.

No production source was modified for this supplemental comparison, and none of the existing UT3 six operation contracts was promoted or invalidated by these later UDK-only files. No PRD SQL, imported UEDB5 snapshot or original binary was changed.

Reproducible temporary read-only scripts/results: `D:\Temp\UnrealDB\ue3\spec-review-20261105\probe-three-ue3-corpora.py`, `corpus-probe.jsonl`, `check-installed-compressed.php`, `installed-compressed-results.jsonl`, `check-udk-later-examples.php`, `udk-later-results.jsonl`.

## Correction: original UT3 game-source inventory and July-2013 authority (2026-11-05)

**The previous UT3 game addendum was not source-complete.** Earlier inspection focused on `L:\Source\Games\UT3` (modded/cooked game tree), and did not catalogue the original UTGame/UTGameContent modules embedded in January/March-2008 UE3 or the separate `UT3ScriptSource_1.5` tree nested in UDKUltimate. This correction affects the game-source provenance of the addendum, **not** the previously proven bytes of v512 headers/names/imports/exports.

### Inventory, not inferred from folder dates

| Source tree | UTGame UnrealScript | Native UTGame source | Role |
|---|---:|---|---|
| January 2008 UE3 `Development/Src/UTGame` | 838 .uc | 32 .cpp and 10 .h | Original UT3-era game + Core v512 source |
| March 2008 UE3 `Development/Src/UTGame` | 838 .uc | 32 .cpp and 10 .h | 838/838 class files SHA-256 identical to January; sample C++/header SHA identical |
| May 2011 UE3 | 408 .uc | No native game .cpp | Later UDK-adapted game classes |
| July 2013 UE3 | 411 .uc | No native game .cpp | Later UE3 engine/game example; 2013 source is useful for later format revisions |
| UDKUltimate current `Development/Src/UTGame` | 454 .uc | No native game .cpp | Later game/tool source |
| UDKUltimate `UT3ScriptSource_1.5/Development/Src/UTGame` | 908 .uc | No native game .cpp | Best exact retail installed class-inventory match |
| Separate `L:\Source\Games\UT3` | 140 .uc across exported scripts + mods | No .cpp/.h | 1,107 files, primarily cooked maps, packages, mods and config |

January 2008 also contains **276 .uc** files in `Development/Src/UTGameContent/Classes`; the separate 1.5 script source contains **276 UTGameContent + 20 UT3Gold + 14 UT3GoldGame** .uc files. All 838 January class paths appear in the 1.5 UTGame tree, alongside 70 additional classes. None of those 838 corresponding files is byte-identical between January and 1.5, so shared class names cannot be promoted to implementation parity.

### Original installed game class-map differential

On unmodified files under `H:\Epic Games\Unreal Tournament 3\UTGame\CookedPC`, the canonical PHP UE3 reader produced no errors, and source filenames were compared case-insensitively against the actual ClassIndex=0 export ObjectNameText fields.

| Original file / SHA-256 | Actual exported UClass identities | Matching 1.5 source .uc names | January 2008 | July 2013 |
|---|---:|---:|---:|---:|
| `UTGame.u` / `4afc01d750a21a33d2ab1dcf0cb7b914fb4be11f2c4f265b5aef0d8919a82927` | 908 | **908/908** | 838/908 | 358/908 |
| `UTGameContent.u` / `144f38e75e3be47eb2d332b8bd798b3cb584573e8777be361e4275f3d5d29647` | 276 | **276/276** | 276/276 | Not tested |
| `UT3Gold.u` / `e9fdb8134ec16db4f6130b9dd901620dc54c9c8cb2fb8adc7825c1ca0adc7c5e` | 20 | **20/20** | Not in original Jan UTGame | Not tested |
| `UT3GoldGame.u` / `92bab172fbd43f9bb6ba87a8e2479af0f97747c127752f01169850142eb55f2b` | 14 | **14/14** | Not in original Jan UTGame | Not tested |

**Total: 1,218 / 1,218 installed UClass export names match the `UT3ScriptSource_1.5` four-module inventory.** This proves a direct version-matched **class-name coverage** relationship, not equivalence of source code, compiled UnrealScript bytecode, or original Epic source-authenticity of the 1.5 distribution.

The installed `UTGame.u` is version 512/licensee 0, with 26,070 names, 4,037 imports and 88,221 exports. `UTGameContent.u` is likewise v512/0 with 5,974 names, 3,134 imports and 4,031 exports.

### Why July 2013 is not the UT3-v512 master

July 2013 `Core/Src/UnLinker.cpp` preserves version-gated UE3 serialization support, but its `SerializeNameMap` **disregards name-context flags**; the January-2008 UT3-era code filters names according to the active load-context mask. July 2013 also adds later import fixups and class-remapping logic not present in the original v512 engine revision. Its UTGame class inventory matches only 358/908 of the installed `UTGame.u` exported classes, versus 908/908 for the 1.5 script tree. The July-2013 Core tree is therefore a better resource for late UE3 branches, **not** automatically more faithful to stock UT3 v512.

January-2008 native `UTGame/Src/UTGame.cpp` contains `UUTLevelCheckCommandlet` using `GPackageFileCache->GetPackageFileList()` and `GetPackageLinker()` to inspect maps. This is a game-specific caller of the engine loader; scanning the 32 native game C++ files and 10 headers did not identify a replacement summary/name/import/export table wire serializer. Therefore this inventory does not prove any new required change in UnrealDB's metadata-only UE3 reader.

The standalone UT3 game addendum from the earlier limited game-folder-only review must be **revised for source completeness**. The active v512 package metadata specification and source-scoped dependency/reader contracts remain valid within their existing accepted evidence. No UE3 PHP source, PRD database, original package or UEDB5 snapshot was changed for this source audit.

Reproducibility: `tests/evidence/ue3/ut3-all-source-revision-provenance-and-installed-class-parity-20261105.json` plus the source-inventory/class-differential scripts under `D:\Temp\UnrealDB\ue3\spec-review-20261105`.
