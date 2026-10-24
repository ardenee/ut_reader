# UE1 O06 — genuine compiled UT99 oracle milestone

Date: 2026-10-23. **Evidence milestone VERIFIED; overall O06 G5 IN PROGRESS, NOT ACCEPTED.**

## Purpose and exact scope

Use an independently compiled game engine (installed UT99 v432) to compare
actual runtime-loaded objects against UnrealDB's on-disk interpretation of
the same genuine `Engine.u`. This does **not** assert that the v432 binary
was built from the pinned UT99 v400 source, nor that all default values,
other serializers, memory changes or linker side effects are correct.

The original read-only installation at `H:\Epic Games\UnrealTournament\System`
was copied into the disposable
`C:\Temp\UnrealDB\O06\Native-UCC\System`.
102 executable, DLL, settings and `.u` files were copied; six originals
were SHA-256 compared to their temporary copies. No native test executed
in the installed game directory or on PRD.

Independent native compiled command (working directory: disposable System):

```text
ucc.exe batchexport Engine.u TextBuffer txt C:\Temp\UnrealDB\O06\Native-UCC\exports\EngineTextBuffer
```

The first exploratory `batchexport Engine.u Class uc` run did load the
package but then encountered a native **exporter** assertion because one
native UClass lacked ScriptText. This was not an UnrealDB parse error.
The subsequent `TextBuffer` commandlet run completed with native log
`Success - 0 error(s), 0 warnings` and clean object subsystem shutdown.

## Direct observation and comparison

- The compiled engine loaded real `Engine.u` and exported **88 distinct
  `UTextBuffer` UObject full paths**.
- UnrealDB independently found **88 matching TextBuffer ExportMap rows**
  and derived their complete `Engine.ClassName.ScriptText` outer paths.
- The entire native vs PHP path multiset was **88/88 exact matches**,
  no extras or missing references. No name-only approximations.
- The compiled exporter wrote the last object,
  `Engine.ClipMarker.ScriptText`, as an **826-byte** text file.
  The production UnrealDB reader decoded its source `UTextBuffer`
  archive record completely and retained **830 bytes** of text.
  The 826 exported bytes exactly match the original first 826 bytes;
  the other four original bytes are precisely CR LF CR LF.
- Epic UT99 v400 `Editor/Src/UnEdExp.cpp`,
  `UTextBufferExporterTXT::ExportText`, explicitly strips leading
  and trailing CR, LF and ASCII spaces before `Ar.Log(Start)`.
  Applying **that source-defined exporter transformation** to
  UnrealDB's decoded value yields exact native-exported bytes.
  The on-disk reader correctly **retains** original line endings.
- Native engine v432 is a real independently built executable.
  Its success verifies selected *observed* load/export behavior,
  not all v400/v432 source branches or full object-memory semantics.

SHA-256 of checked local artifacts:

| Artifact | SHA-256 |
|---|---|
| Original/deployed-to-disposable `Engine.u` | `d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695` |
| `ucc.exe` | `90cf7eb27bf1dd56767720b01deb29d3169d9e749f4fda87933e715db390742f` |
| `Core.dll` | `237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8` |
| `Engine.dll` | `f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac` |
| `Editor.dll` | `404a394b7edc4b759e3d30d3e7e5e90fe0af48d29a69cad1e71bfb057f0fa01e` |
| Frozen original engine log `NativeEngineTextBufferLoad.log` | `4c1a0139fd9cf56dbac332295e1fbdfd4a7025f2125ae089b48dbbacf908184e` |
| Last exported `ScriptText.txt` | `7ee9596302f2944a8d3831cb3ab4eddfac15efccdb268b4837557451244fac57` |

The repository test
`catalog/bin/verify-ue1-o06-native-ut99-textbuffer-exports.php`
validates the original artifact hashes, Epic exporter source branch,
native clean-exit/LoadPackage evidence, the **88 full paths**, the
decoded native TextBuffer record and exact exporter-transformed bytes.
DEV focused run: **22/22 PASS**, 118 ms. The first logged failed
attempt compared two equivalent associative arrays using order-sensitive
strict equality; this *test-only* defect was fixed without changing
the production reader and the original FAIL remains journaled.

Raw original runtime logs and export text stay under `C:\Temp`, not
inside the GitHub repository or Graphify directory.

## Acceptance boundaries and next steps

**G5 is in progress only.** Next acceptance activity must compare
additional persisted property/struct/mesh/level values and historical
1996/1997 source families with independent genuine data, then reconcile
all 197 PARTIAL source sightings by *distinct source contract*, rather
than re-running unrelated passing tests. 292 source sightings are not
292 independent bugs. G1 SOURCE-MAPPING accepted; G2-G5 open; G6 blocked.

No production PHP code changed in this milestone; no PRD copy or PHP
test is needed. New tests run on DEV only. Full 30-package regression
remains separately pinned and gate-only; it was **not rerun** here.

## G5 — real compiled UT99 Sound payload parity (2026-10-23)

**New independent native engine evidence — not full G5 or O06 acceptance.** The disposable UT99 **v432** compiled `ucc.exe` successfully loaded genuine `UnrealShare.u` and ran `batchexport UnrealShare.u Sound wav C:\\Temp\\UnrealDB\\O06\\Native-UCC\\exports\\UnrealShareSounds`, ending with **Success - 0 error(s), 0 warnings**. This yielded **262 distinct native UObject full paths** and 262 original WAV files, totalling **7,225,244 bytes**. Engine v432's compiled source version is not established as byte-identical to the pinned UT99 v400 source.

Epic UT99 v400 `Editor/Src/UnEdExp.cpp::USoundExporterWAV::ExportBinary` calls `Sound->Data.Load()` then `Ar.Serialize(&Sound->Data(0), Sound->Data.Num())`, i.e. emits **original audio bytes without WAV reconstruction**. From the exact same `UnrealShare.u`, UnrealDB's production `CatalogUE1ClassicTaggedPropertyReader` and `CatalogUE1ClassicNativeObjectReader` resolved **262/262** real export outer chains and decoded **262/262 native_sound_record_complete** records. Every physically bounded audio span matched the independent compiled exporter byte-for-byte by SHA-256 and length, **262/262, zero missing paths, zero mismatches**. This is actual on-disk/native behavioral evidence, not synthetic fixtures. The first attempted independent `BotPack.u` load reported a missing `Female2Voice` dependency in the disposable source installation, so that attempted package produced **no acceptance evidence**; only successful `UnrealShare.u` is counted.

Immutable runtime anchors (original bytes remain only under `C:\\Temp\\UnrealDB\\O06\\Native-UCC`):
- `UnrealShare.u` SHA-256 `d75a7a5a4dbdcbcbfc363eadae810f99dfc4030c203032ed2dc88d255811155d`;
- `ucc.exe` SHA-256 `90cf7eb27bf1dd56767720b01deb29d3169d9e749f4fda87933e715db390742f`;
- frozen native export log SHA-256 `38d4da7f9fc4717d5289315690a1c9286ed174eb5412d57a691522f7f24c67d6`;
- canonical SHA-256 over sorted `lowercase UObject full path TAB byte size TAB WAV SHA256 LF`: `adb13e1895ab3aae967a8b47b803c2fbb9d12c0b9c348849e058e1f48ea09400`.

Production-source-aware, side-effect-free DEV verifier `catalog/bin/verify-ue1-o06-native-ut99-sound-exports.php` checks frozen identities, no native failures, original v400 exporter contract, all source export outer links, true original native audio tail decoding, physical bounds, each of the 262 byte hashes, exact aggregate object/byte count and sealed canonical content digest. **DEV focused acceptance PASS in 313 ms**; journal `20261023T153110017Z-e725eeae`. No PHP change, no PRD execution/deployment, no unnecessary 113MB suite rerun. This advances G5 genuine persisted native audio evidence but **G5 remains IN PROGRESS**: actual loaded in-memory per-property comparison, meshes/levels and separate early 1996/97 compiled oracles remain open. No O06-wide acceptance.

## G5 compiled UT99 UMusic lazy-array native comparison (2026-10-23)

**Genuine persisted value parity established for 30 original .umx tracker packages; O06 / G5 not accepted overall.** The *actual installed* `H:\Epic Games\UnrealTournament\Music` directory contains 30 `.umx` music archives. For reproducibility each was copied (SHA-256 verified unchanged) to the disposable `C:\Temp\UnrealDB\O06\Native-UCC\System` directory and passed to compiled native UT99 v432 `ucc.exe batchexport PACKAGE.umx Music it OUTPUT_FOLDER`. All **30/30 independently native LoadPackage + ExportBinary calls succeeded**, zero engine errors/warnings, and 30 native tracker files were produced. The `it` requested export extension is only a filename convention; Epic's exporter serializes the original `UMusic::Data` bytes without conversion, so the package `FileType` source can legitimately be `it`, `xm` or `s3m`.

Pinned Epic UT99 v400 `Engine/Src/UnAudio.cpp::UMusic::Serialize` writes `FileType` then `Data` (`TLazyArray<BYTE>` when loading), and `Editor/Src/UnEdExp.cpp::UMusicExporterTracker::ExportBinary` calls `Music->Data.Load()` and archives all actual `Data.Num()` bytes unmodified. Production `CatalogUE1ClassicTaggedPropertyReader` + `CatalogUE1ClassicNativeObjectReader` independently decoded 30 actual class `Music` exports, confirmed 30 `native_music_record_complete` status records, linked every complete UObject outer path, checked serialized boundaries and the lazy SeekPos, and SHA-256 matched **49,460,075 of 49,460,075 bytes** against the compiled export files; **0 mismatches, 0 omitted exports**.

Frozen original runtime artifacts remain only under `C:\Temp\UnrealDB\O06\Native-UCC`, not Graphify, Git, or PRD:
- Native 30-package package-SHA / original-log-SHA manifest `NativeMusicAllManifest.json` SHA-256 `5f6c9e7e557532c8660713af7892e6dad04b70e7b4c539c2994a0af1744d8fb5`.
- Original source `UnAudio.cpp` SHA-256 `c648a3106b1ea910da178b17324ccf8c9d9eaf04b647671277019c61291f7ea8`; original `UnEdExp.cpp` SHA-256 `dfeb1650641043996313da358f897daf3b5de4da66affe820d4e930e4d4f070f`.
- Canonical digest of 30 sorted `lowercase full UObject path TAB payload size TAB payload SHA-256 LF` records: `327fbff329e66ede3ded4a9cbf7d9a63b114fca7df7f6ba52fcfd9c11bdc9925`.
- Production-decoder verification `catalog/bin/verify-ue1-o06-native-ut99-music-all.php` with **30 independently checked named artifacts** and strict aggregate digest: scoped DEV focus **PASS** at `20261023T161530485Z-7237246f` in **317ms**; append-only test event and evidence saved.

Native v432 is not proven compiled from exactly the pinned v400 source, and this verifier does not validate engine memory effects, tracker decoding/playback (O07), original v61 or 1996-97 builds, textures/meshes/levels, or every property type. A *separate* native `UTextureExporterPCX` attempt on `UnrealShare.u` crashed after two exports inside compiled Epic `UTextureExporterPCX::ExportBinary`; attempted `UTMenu.u` failed due missing `Female2Voice` dependency. Neither was counted as UnrealDB failure or native acceptance. No production PHP changed, no PRD test or deployment required. G5 remains IN PROGRESS, G2-G4 OPEN, G6 BLOCKED, O06 NOT ACCEPTED.

## G5 v432 compiled native method evidence (2026-10-23)

The installed and disposable v432 Engine.dll SHA256 f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac contains UAnimation::Serialize method at RVA 0xECEC0, USkeletalMesh::Serialize at RVA 0xED290 and UFont::Serialize at RVA 0x846D0 (their exported entry stubs are different RVAs). The first two method field orders are matched to v432 UnSkeletalMesh.h/UnAnim.h/UnMesh.h source operators and implemented in UnrealDB. Synthetic positive and negative source-byte fixtures under C:\Temp\UnrealDB\O06 passed for both, including inherited ULodMesh. UFont is reopened because its compiled v69+ method also writes CharRemap and IsRemapped. Full genuine v432 skeletal value parity, font remap decoding and source-wide G5 remain OPEN. See UE1-UT99-V432-COMPILED-SERIALIZERS.json.

## v432 UFont compiled CharRemap archive detail (2026-10-23)

Engine.dll UFont::Serialize RVA 0x846D0 proves a version 69+ TMap<TCHAR,TCHAR> remap and IsRemapped archive tail. Its compiled TMap specialization RVA 0x84D00 shows eight-byte in-memory TPair stride but archives 2-byte key and 2-byte value, prefixed by compact array count; IsRemapped uses a separate 4-byte word. Pre-69 reads no map and sets the flag to zero. Production PHP implements and bounds both branches; focused DEV fixture verifies positive and negative cases. No genuine v432 font loaded-value parity has been accepted yet.

## G5 actual installed UT99 v432 font bytes, and current native slice revalidation (2026-10-23)

A fresh DEV inventory of 145 installed UT99 System .u / Textures .utx packages found 34 genuine UFont exports in six SHA-pinned archives: Engine.u (4), BotPack.u (5), UnrealShare.u (5), LadderFonts.utx (10), Scripted.utx (1), UWindowFonts.utx (9). The new scoped verifier catalog/bin/verify-ue1-o06-g5-genuine-v432-fonts.php journaled PASS 66/66 checks (20261023T220148118Z-ef7858ef, 388ms) on the current source fingerprint c5fcd53d482d93cf2e43eaa9379f0845ea1d1cdb43b1e2a4a0a9facec6a348ce. All 34 have native_font_record_complete, zero unconsumed native bytes, positive page counts and correct tagged-property envelope. Nine v69 fonts archive the CharRemap/IsRemapped tail with count=0 and IsRemapped=0; 25 v68 fonts take the compiled version<69 branch without a tail and with runtime flag zeroed. Thus the genuine v69 *zero-pair* and legacy archive cases are verified against actual files, not merely synthetic fixtures. Nonempty CharRemap pairs, compiled native in-memory font values and complete object reference identity remain OPEN. This is not complete UFont or overall G5 acceptance.

An invalidated native UMusic verifier initially FAILED because its own invocation did not supply known UT99 game lineage for ambiguous version 62-66 music files. Failure was journaled (20261023T220311679Z-89f44f92); product readers correctly reject archive-version-only provenance and were not weakened. Three native evidence verifiers were corrected to pass explicit ut99-v432 loader source to both tagged-property and native readers. They then passed current-source focused G5 regression: UMusic 30/30 genuine exported trackers / 49,460,075 bytes (20261023T220402575Z-9b74373b); USound 262/262 genuine WAV archives (20261023T220406111Z-8a4ddd8f); UTextBuffer 88/88 compiled full object paths and the pinned exporter-transformed value (20261023T220410769Z-3845d89f). These are refreshed previously established independent slices, not three newly covered serializers. No production PHP source changed for this G5 verification, and PRD has not been deployed.

## G5 compiled v432 UFont loaded-value and reference identity oracle (2026-10-23)

**New independent runtime evidence, but not whole-method/O06 acceptance.** A read-only production ExportMap scan of all **310** installed v432 UT99 packages across System, Textures, Maps, Sounds and Music returned zero structural errors, 34 Font exports, **zero USkeletalMesh exports and zero UAnimation exports**. The exact survey output is retained under `C:\Temp\UnrealDB\O06\g5-full-install-candidates.json` (SHA-256 `559dde47759448c4507ac6ae059eafcb625bf5fc38966c4cab23fcabc87eae41`). Only these installed files were scanned; the absence of skeletal/animation *in this installation* is not proof that the class is nonserializable or absent elsewhere.

The installed UT99 v432 compiled `Engine.dll` (SHA-256 `f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac`) was executed from the disposable `Native-UCC\System` by Microsoft CDB x86. CDB broke after the real compiled `UFont::Serialize` archive instruction at RVA **0x8486B** (method RVA 0x846D0). It captured all four genuinely loaded Engine.u v69 fonts: `SmallFont` export 2634, `MedFont` 5088, `LargeFont` 5091 and `BigFont` 5094. Each native object held `CharactersPerPage=256`, one `FFontPage`, 256 `FFontCharacter` records, zero CharRemap entries, `IsRemapped=0` and a valid texture-object pointer. All four complete native in-memory FFontCharacter arrays were independently dumped with CDB `.writemem` (**4 x 4,096 = 16,384 bytes**). Their separate SHA-256 values matched UnrealDB's PHP source-family-selected production field archives exactly, 4/4 with no mismatch.

For **actual reference identity**, another CDB break inspected the compiled `UObject::_LinkerIndex` at offset +0x14 on both each loaded UFont and its `FFontPage.Texture` pointer. Native font indices `2634,5088,5091,5094` matched PHP font exports; loaded texture indices `5086,5089,5092,5095` matched PHP on-disk positive linker references `5087,5090,5093,5096` minus one. Thus all **four object reference identities** were independently matched, not just pointers being non-null. Source declaration: v432 `Core/Inc/UnObjBas.h` UObject and `Engine/Inc/UnTex.h` UFont/FFontPage.

The native CDB log captures and binary arrays remain under `C:\Temp\UnrealDB\O06`. File identities, frozen debugger log SHA values, exact exported object/texture indices and all four native 4,096-byte hashes are versioned in `UE1-O06-G5-FONT-NATIVE-LOADED.json`. **New focused DEV acceptance verifier** `verify-ue1-o06-g5-native-v432-font-loaded-values.php` passed **61/61**, journal run `20261023T224137762Z-0af749c6`. Two earlier failed attempts remain append-only, caused only by verifier parsing assumptions (native CDB `.logopen /u` is UTF-16LE BOM, and CDB stdout is not copied into its log); corrected without changing production PHP. The native `batchexport Engine.u Font txt` command has no Font TXT exporter and exits with an exporter error **after** executing the four observed compiled `UFont::Serialize` calls. Do not confuse this commandlet exporter limitation with native serialization failure.

**Remaining G5:** nonempty v69 CharRemap runtime values, nonzero IsRemapped, malformed-input native outcomes, 30 other genuine Font exports, and skeletal/animation genuine specimens remain OPEN. This is one independently verified **loaded-value subset**, not full UFont method acceptance. No PRD deployment or production reader modifications.

## G5: Original PRD UE1 corpus and v432 compiled-native comparison (2026-10-23)

The user identified an additional read-only corpus at \\192.168.0.55\l$\unrealdb. DEV enumerated 74,395 packages under games/ut99/verified and 10,934 under games/unrealgold/verified (85,329 total filenames with UE package extensions). Original package names were identified from the existing PRD file-identity-backups CSV. This is not a blanket assertion that all files are valid for v432: game, archive version and selected source-family provenance remain separate. In particular, packages whose header specifies Unreal v1.227 (69/227) are rejected when the ut99-v432 selector is explicitly requested, despite the UT99 catalog label. All originals were accessed read-only; PHP execution occurred on DEV only.

**Genuine skeletal mesh/animation:** the first 250 UT99 verified .u entries yielded 6 USkeletalMesh and 4 UAnimation exports across four unmodified v69 files: table.u, EcoTerror.u, d00d.u and SFCharacter4.u. SHA-pinned copies were examined on DEV. All ten native records decode completely in UnrealDB with zero extension bytes. The installed compiled v432 Engine.dll was run using disposable original-named package copies and necessary dependencies, with Microsoft CDB breakpoints after the actual native archive methods. For table.u, EcoTerror.u and d00d.u, 5 genuine USkeletalMesh and 3 genuine UAnimation objects were observed in native memory, including matching array Num/Max and selected scalar fields. Three positive default-animation references also resolve to the expected compiled UObject linker export identities. DEV focused verification **151/151 PASS**, 20261023T230435525Z-504a1849. Initial verifier failure was a native UTF-16LE CRCRLF section-parsing issue, corrected with original failure evidence preserved. The remaining SFCharacter4.u exports decode at file level but compiled loading is blocked by external package dependencies. Package names, SHA, CDB logs and exact export indices are pinned in UE1-O06-G5-PRD-NATIVE-SKELETAL-ANIMATION.json. This verifies only subsets, not every array element or error path.

**Genuine nonempty v69 font remaps:** targeted original-named PRD font search found 121 nonempty UFont exports in five UT99-v432-compatible package files, not merely artificial remap fixtures. Two original variants of UWindowFonts.utx were loaded successfully by compiled v432. CDB observed all **29+33=62** fonts with correct loaded CharactersPerPage, page Num/Max, CharRemap Num/Max, IsRemapped=1, and full native serializer execution. The first variant has 255 entries per font; second has 1,183 entries per font, totaling **46,434 loaded remap entries**. Native CDB memory dumps of the complete 255*8=2,040-byte and 1,183*8=9,464-byte TMap backing arrays matched every four-byte serialized key/value pair in original source order, through independent exact SHA-256 comparison against UnrealDB's production decoder. First-font glyph-count values of 256 and 2,048 also matched native page memory. Focused DEV verification **467/467 PASS**, 20261023T230933123Z-cf294e12. SHA-pinned original packages, CDB captures, complete map dumps and expected identities are recorded in UE1-O06-G5-PRD-NATIVE-NONEMPTY-FONTS.json. Previously verified 4 genuine Engine.u font 4096-byte glyph arrays plus 4 native texture-reference identities remain valid.

**Remaining error-handling discrepancy:** On a clearly labelled, isolated, controlled mutation (not a genuine-file oracle) of disposable v69 Engine.u with one remap count byte changed, compiled v432 consumed four bytes past the declared font SerialSize and reported 'Font Engine.SmallFont: Serial size mismatch: Got 4116, Expected 4112'; production PHP rejected the count earlier with 'UT99 v432 font CharRemap pairs exceed native bounds'. Both reject; their exact byte-consumption and diagnostic semantics demonstrably differ. Evidence: UE1-O06-G5-FONT-NONEMPTY-AND-MALFORMED.json. Another size-preserving artificial four-entry remap fixture was accepted by the compiled engine, but this is now superseded as format evidence by the authentic 255/1183-entry cases.

**Strict G5 accounting:** All 256 mapped methods still MAPPED with 38 EXCLUDED; three T99 methods now have independently observed native loaded-value subsets (UFont::Serialize, USkeletalMesh::Serialize, UAnimation::Serialize), previously only UFont. Full G5 method acceptance remains **OPEN** for all methods until all relevant fields, references, array elements, byte/error paths and case gates are independently demonstrated or explicitly limited. The previous installed-UT99 310-file missing-specimen conclusion applies only to that smaller installed corpus, not the new PRD repository. There were no production PHP changes and no PRD deployment.

## G5 authentic compiled USkeletalMesh and UAnimation full array-element subsets — 2026-10-24

**Verified real files on DEV, PRD read-only.** Build-selected UT99 v432 compiled `Engine.dll` (SHA-256 `f87a979d6a2ead47e92e3e779142953d7748a9feb068635629bb4017e4b0c7ac`) was run through `ucc.exe` with the original SHA-pinned UT99 version-69 `table.u`, `EcoTerror.u`, and `d00d.u` source files and their dependencies in a disposable DEV Native-UCC installation. CDB interrupted at the actual native serializer post-archive instructions, `USkeletalMesh::Serialize` RVA `0xED415` and `UAnimation::Serialize` RVA `0xECF21`. No native engine or package file was modified.

1. **Complete native skeletal bone arrays.** Across five genuine loaded `USkeletalMesh` exports, all five `VBoneInfIndex` arrays (8 bytes/element), and all five `VBoneInfluence` arrays (4 bytes/element), were dumped from exact in-memory `TArray` pointers. Independently compare each complete dump to the production reader's archived array byte ranges and all decoded semantic previews: **42,032 bytes, every element identical**. Source operators are from genuine v432 `Engine/Inc/UnSkeletalMesh.h`; no unproved assumptions that counts alone demonstrate parity. `UE1-O06-G5-FULL-ARRAY-NATIVE.json` freezes SHA-256 of each original package, native dump and debugger log.

2. **Complete native animation named-bone arrays.** Across three genuinely loaded `UAnimation` exports, all **143** `FNamedBone` entries (48, 47, 48; `12` in-memory bytes each = **1,716 bytes**) were independently decoded. Epic uses global `FName::Names` table indices in native memory, which do *not* equal the package-local compact FName indices; the comparison correctly dereferenced the compiled native global `FName::Names` table and `FNameEntry::Name` Unicode strings via CDB, checking **each bone name** (not merely index), flags and signed parent index against the original archived `FCompactIndex + uint32 + int32` and UnrealDB source-family decoder. Every one of the 143 triple comparisons matched. The exact native resolved name logs, immutable full RefBones array dumps and source binaries are SHA-pinned in the same manifest.

3. **Complete native skeletal vector arrays.** CDB independently dumped all `Points` (`this+0x1BC`) and `LocalPoints` (`this+0x1EC`) backing arrays from those same five loaded USkeletalMesh exports; each FVector is three 32-bit floats (12 bytes). **10 complete arrays = 18,813 vectors, 225,756 bytes**, all byte-for-byte equal to the original production reader's source archive ranges and matching first-16 decoded XYZ semantic previews. `UE1-O06-G5-VECTOR-ARRAY-NATIVE.json` contains the exact package, binary dump and native CDB log SHA-256 identities.

**Focused, append-only acceptance results:** `verify-ue1-o06-g5-real-skeletal-animation-full-arrays.php` **733/733 PASS**, journal `20261023T232906204Z-00faa6bc`; `verify-ue1-o06-g5-real-skeletal-vector-arrays.php` **228/228 PASS**, journal `20261023T233107304Z-bfeaef38`. **Combined 961/961 current new checks; 269,504 bytes of native real-array data**, comprising 42,032 index/influence bytes + 1,716 named-bone bytes + 225,756 vector bytes. Neither check replays earlier genuine loaded-value verifiers. Only focused evidence/test files changed; production PHP still unchanged.

**G5 not closed:** `USkeletalMesh::Serialize` still needs genuine native matching for all `RefSkeleton` FMeshBone fields and the inherited `ULodMesh` path, plus remaining extras; `UAnimation::Serialize` needs actual full `Moves`/`AnimSeqs` nested values and native malformed-input behaviour. `SFCharacter4.u` two authentic exports are still dependent on unavailable external packages for native loading. Per-method FULL G5 acceptance remains OPEN; no PRD writes or deployment.

## G5 — restartable compiled native v432 batch: FMeshBone and AnimSeq/Notifys (2026-10-24)

A new **finite two-contract resumable batch** is versioned at `catalog/bin/run-ue1-o06-g5-resumable-batch.ps1`. It uses the existing append-only O06 test journal, the exact source/test SHA-fingerprint PASS cache, and an atomic local progress marker at `C:\Temp\UnrealDB\O06\g5-native-resumable-batch-state.json`. It is safe to re-run after interruption: only missing or invalidated focused results are actually executed; a matching PASS is `UNCHANGED_PASS` and remains in the earlier immutable log. Use `powershell -NoProfile -ExecutionPolicy Bypass -File catalog/bin/run-ue1-o06-g5-resumable-batch.ps1` from DEV repo root, or add `-Status` for the local step marker. `-Force` deliberately bypasses cache for explicit investigation and must not be the default. A second launcher execution demonstrated **both tests skipped as UNCHANGED_PASS**, 0 new tests executed, unchanged source SHA. PRD was only ever read-only. No production PHP edits.

**Contract 1: full genuine reference skeleton.** SHA-pinned original `table.u`, `EcoTerror.u` and `d00d.u` version-69 files were loaded through compiled UT99 v432 `Engine.dll`/`ucc.exe`. CDB post-`USkeletalMesh::Serialize` loaded all 5 real `USkeletalMesh` exports and dumped **237 `FMeshBone` records**, exactly **15,168 bytes** of live native memory. Each record was compared with the original package byte stream via the production UT99-v432 decoder and independent parser: all 237 name identities resolved through compiled `FName::Names`, flags, quaternion and position and length (32 raw bytes), second serialized `XSize`, `ZSize`, parent and child-count values match. The original v432 `UnSkeletalMesh.h` `VJointPos::operator<<` serializes `XSize` twice; the second write wins. Native `YSize` and `FMeshBone::Depth` are uninitialized nonserialized in this operation (`0xBAADF00D` fill in all 237 captured records) and **must not be represented as serialized field contents**. Test `verify-ue1-o06-g5-native-refbones-v432.php` **1787/1787 PASS**, run `20261024T074753646Z-7d3a4af8`.

**Contract 2: all actual animation sequences plus notifies.** Compiled v432 post-`UAnimation::Serialize` CDB memory captures **70 native `FMeshAnimSeq` records**, 68 from `EcoTerror.u`, two from `d00d.u`, exactly **2,240 bytes**. All native names and groups were independently resolved through the compiled global name table; start frame, frame count, playback rate and Notify Num/Max were compared against every serialized record and UnrealDB decoding; every record matches. For all 22 nested `FMeshAnimNotify` records in the 68 genuine EcoTerror sequences, CDB additionally resolved each native function name and actual 32-bit float timestamp; all match the serialized source values. `table.u`'s animation has zero sequences (no fabricated coverage). Test `verify-ue1-o06-g5-native-animseq-notifies-v432.php` **575/575 PASS**, run `20261024T074756286Z-b04f9000`.

**Total this batch: 2362/2362 newly executed checks**, two separate focused journal entries, SHA-pinned manifest `catalog/compliance/UE1-O06-G5-NATIVE-RESUMABLE-BATCH.json` with per-package/per-DLL native CDB and memory-dump hashes, no previous test replay. Three selected v432 compiled serializer methods still have loaded-value subsets; new array-element corpus total rises from 269,504 to **286,912** observed runtime bytes across selected array families. **No whole method accepted**: `USkeletalMesh` inherited `ULodMesh` and further fields remain, `UAnimation` `MotionChunk`/`Moves` nested animation arrays remain, native malformed error paths remain, and the other UE1 source-family oracles remain open. These are intentionally not counted as 100% Epic source parity or O06 closure.

## G5 compiled v432 UAnimation MotionChunk scalar/header/nested first-track oracle - 2026-10-24

New exact targeted compiled-native result on DEV. The original SHA-pinned UT99 version-69 EcoTerror.u and d00d.u from the PRD read-only verified corpus were loaded under the unchanged compiled native v432 Engine.dll/ucc.exe. At the actual UAnimation::Serialize post-archive method instruction Engine.dll RVA 0xECF21, Microsoft CDB captured all 68 + 2 = 70 complete in-memory MotionChunk structs (88 bytes/record, 6,160 bytes total), independently compared against source archive fields and the production UT99-v432 reader. Every record's root speed XYZ, TrackTime, signed StartBone, Flags, BoneIndices TArray Num/Max, AnimTracks TArray Num/Max, and RootTrack Flags plus nested Num/Max counts agree exactly with the source-defined archive stream. Runtime pointers are not treated as serialized field values.

CDB also dumped the first Move's 47 + 48 = 95 complete 40-byte AnalogTrack structs from the two original packages. Every track's Flags and three nested array Num/Max fields match actual source-defined compact-indexed KeyQuat/KeyPos/KeyTime archived counts. The first Move's entire BoneIndices buffers (188 + 192 bytes) and the first AnalogTrack's complete Quaternion, Position, and Time buffers (EcoTerror: 160+120+40; d00d: 16+12+4) match all 732 archived bytes independently, not just counts. All other nested AnalogTrack key buffers remain OPEN.

New scoped verifier verify-ue1-o06-g5-native-v432-motionchunk-headers.php, 598/598 PASS, DEV journal 20261024T080958061Z-783e25a3. SHA-pinned original package, compiled DLL, in-memory dumps and CDB method logs appear in UE1-O06-G5-MOTIONCHUNK-NATIVE.json. No production reader or PRD code changed.

The three-case runner catalog/bin/run-ue1-o06-g5-resumable-batch.ps1 includes this new contract after two previously successful FMeshBone and AnimSeq/Notify contracts. Repeated execution confirmed three of three UNCHANGED_PASS; all three skipped with no repeated tests. It is safe to rerun after interruption; the append-only O06 START/PASS/FAIL and source/test SHA hashes are authoritative, not the transient resume marker. G5 full-method acceptance remains OPEN for inherited ULodMesh, all other nested track key data, other source families and malformed-input native error/byte paths.

## G5 — whole genuine v69 UAnimation MotionChunk nested arrays against compiled v432 memory (2026-10-24)

**Genuine compiled memory oracle:** Two SHA-pinned original UT99 version-69 UAnimation packages (EcoTerror.u, 68 Moves; d00d.u, 2 Moves) were loaded by the untouched real UT99 v432 Engine.dll via UCC, and CDB interrupted at compiled UAnimation::Serialize post-archive RVA 0xECF21. Two full user-mode MiniDump MEMORY64 snapshots, ~46.7 MB and 45.8 MB, were captured to DEV C:\Temp\UnrealDB\O06, **not committed**. Their original SHA-256 identities and the CDB logs' SHA-256 hashes are pinned in UE1-O06-G5-MOTIONCHUNK-FULL-NATIVE-MEMORY.json. These are live native process snapshots, not simulated/untrusted decoded values.

The new independent native parity verifier reads the MiniDump MEMORY64 virtual address regions and dereferences every actual MotionChunk TArray, AnalogTrack TArray, BoneIndices TArray and all child KeyQuat, KeyPos, KeyTime arrays, including all RootTracks. The source side is the original on-disk archive at the production reader's genuine UT99-v432 native-tail data offset; serialized payload parsing is independently bounded by Epic UnSkeletalMesh.h declared field order. The verifier checks every in-memory object scalar/flag, nested Num/Max, and **every actual key byte** against the original file bytes. It also verifies representative float32 quaternion/vector/timing decoded records from the production PHP reader, not just source-byte comparisons.

**Focused result 29,145/29,145 PASS**, DEV journal 20261024T082534795Z-89fb2a79: 70 genuine MotionChunks, 3,292 in-memory AnalogTracks, 10,156 native array buffers (zero-length buffers included), **540,836 bytes of nested bone/quat/position/timing data** all equal to original native archive bytes. 21,020 quaternion keys; 9,268 vector-position keys; 21,020 key-time values. The first attempt was **29,144/29,145 FAIL**, journal 20261024T082448302Z-b87602db, entirely due to verifier's cumulative byte tally being compared with second case's individual total: no native array mismatch. The test-only aggregation formula was corrected, **the prior FAIL remains immutable and journaled**, then full new PASS completed.

The finite 4-contract resumable batch includes the earlier FMeshBone 1,787, AnimSeq/Notify 575, partial MotionChunk headers 598, and new full-native-memory 29,145 focused tests. Rerun of the launcher demonstrated **4/4 UNCHANGED_PASS**, zero repeated test executions. The two original source packages and compiled DLL identities have remained unchanged; PRD read-only, no production PHP edits. Reconciliation has two methods with demonstrated full-array *subsets*, three selected methods with native loaded-value subset evidence, and 0 methods fully accepted because UAnimation still needs parent/inherited/error semantics and independent other source-family cases; USkeletalMesh still needs inherited ULodMesh, malformed native paths, and other specimens. O06 remains OPEN.
