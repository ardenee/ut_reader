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
