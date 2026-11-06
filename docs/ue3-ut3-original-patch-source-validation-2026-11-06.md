# UE3 file-format and UT3 game-addendum validation against recovered source-version binaries

**Audit:** 6 November 2026
**Source-only manuals:** Unreal Engine 3 Package File Format Specification, edition 1.2; Unreal Tournament 3 Game-Specific Format Addendum, edition 1.2.
**This is a separate conformance report.** The two manuals do not contain implementation-specific or database instructions.

## Verdict and evidence hierarchy

**The available evidence closes the source-to-physical-layout uncertainty across seven UT3 patch releases, and adds genuine original sample validation for early 2006 UE3 and later 2008–2013 UE3 source revisions.** It does **not** certify all historical UE3 revisions, console codec variants, licensee changes or compiled native loader operations.

- Source authority: 2006, January-2008 and July-2013 original UE3 `Core/UnLinker` implementations, plus their version gates; original UT3 native and UnrealScript game modules as a distinct game-only source layer.
- UT3 original update evidence: **12 recovered patch archives**, independently SHA-pinned on DEV; **seven unique executable/script binary sets**. No installers launched. Archived original binaries remain outside Git.
- Reader validation: **90 distinct SHA-256-original script packages**, **152 entries across all 12 patch archives**; all unique packages parsed with zero reader issues and exact summary counts. Each is structural **FileVersion 512/licensee 0**, regardless of the release label.
- Source-independent raw header differential: **1,080/1,080** package-summary values compared on the 90 unique UT3 packages, zero mismatches. The independent parser read original physical summary bytes directly; this **does not** independently reimplement compressed LZO payloads.
- Early UE3 format: original **33/33 version 402–429** packages from the 2006 source distribution were parsed by an independent Python decoder that follows that revision's actual summary, name/import/export and dependency-table version gates, including the absence of the later `FObjectExport.PackageFlags` word. All 33 are uncompressed; zero failures.
- Later UE3 coverage: **38 different observed version values, from 491 to 867**, each represented by an original file from source distributions. All **38/38** are accepted by the current UE3 reader, and **304/304** independent physical-summary comparisons match. The original files exercise each selected observed version, **not every integer value** or every platform.
- Existing independent full field/offset oracle for seven genuine v512 packages remains retained: 35,593 names, 17,904 imports, 34,635 exports, zero field mismatches. It was reused rather than rerun.

## UT3 release progression: original bytes, not filename guesses

The two package-file version words are unchanged across these seven releases: **FileVersion 512, licensee 0**, and **CookedContentVersion 57**. The separately serialized `EngineVersion` changes. `EngineVersion` is useful build provenance but **not** a substitute for the structural version gates.

| Recovered original release cohort | EngineVersion | `UTGame.u` UClass exports | Original script `.u` files |
|---|---:|---:|---:|
| Patch 1 Beta 1 | 3508 | 838 | 12 |
| Patch 1 Beta 3 | 3518 | 841 | 12 |
| Patch 1 Beta 4 / release 1.1 | 3521 | 841 | 12 |
| Patch 2 release 1.2 | 3543 | 841 | 13 |
| Patch 3 | 3601 | 842 | 13 |
| Patch 4 | 3797 | 908 | 14 |
| Patch 5 | 3809 | 908 | 14 |

The three additional Patch 1/2 archives are duplicate extracted binary sets; they are **not** independent builds. All original script packages use either **LZO (compression flag 2)** or **no package compression**. None of these UT3 patch binaries provides an Xbox 360 LZX-compressed package or an early family version under 491.

**Important UT3 source-to-binary evidence:**

- Early January-2008 UT3 game source has 838 `UTGame` class names and 276 `UTGameContent` names.
- The extended UT3 script source contains 908 `UTGame`, 276 `UTGameContent`, 20 `UT3Gold` and 14 `UT3GoldGame` class names. These are **game-specific definitions**, not new UE3 wire fields.
- Actual original Patch 4 and Patch 5 `UTGame.u` each contain **908** exported UClass names, all matching the extended UT3 source set; the two new game modules have matching 20 and 14 classes.
- Patch 1 Beta 1 compiled class names **do not exactly equal** the 838 January-2008 game-source names: there are five asymmetric differences in each direction, including two old History classes absent from the later source. This prevents incorrectly retrofitting an early game's entire class source from a same-sized later inventory.
- Beta 1 → Beta 3 has five added and two removed `UTGame` classes. Patch 3 adds one `UTUITabPage_VoteTab`; Patch 4 adds **66** relative to Patch 3. Patch 5 retains the same 908 class names but changes package bytes, table counts and engine build.
- Shared class names and different package bytes **do not prove** identical UnrealScript source bodies, compiled bytecode, default properties or runtime native behaviour.

## Actual UnrealDB metadata handling — separate from source-only manuals

| Source-defined concern | Source-selected implementation | Evidence and status |
|---|---|---|
| UE3 reader ownership | `CatalogReaderResolver` → `UE3CatalogReader.php` → `EpicUE3PackageReader.php` | **PASS**; separate concrete UE3 parser |
| UT3 original version/licensee | `Uedb5Ut3SnapshotBuilder` source policy requires v512/0 | **PASS**; no source-policy leakage to other UE3 version/licensee pairs |
| Tag, byte order and summary | UE3 parser `parse()` | **PASS for tested original cohorts**; 1,080 UT3 and 304 later-family independent summary values match |
| UT3 names, imports and exports | UE3 `readNames()`, `readImports()`, `readExports()` | **PASS for original UT3 patches**; 90 unique package files, zero malformed/partial output accepted; prior exact-field original oracle preserved |
| UT3 compression | UE3 compressed logical map and LZO codec | **PASS for these original PC LZO files**; not an original compiled-engine output-byte differential |
| UT3 source-level import identity | UE3 source-gated effective identity/VerifyImport resolver | **Previous six operation-path contracts remain PASS**; this investigation found no new source-defined condition requiring an implementation change |
| Early UE3 v402–429 | Current generic later-family reader starts at v491 | **KNOWN COVERAGE GAP**; independent source-specific Python decoder validates 33 original cases, but the production reader intentionally returns unsupported for original early files |
| Genuine UE3 v180–186 | 2004-era original package corpus | **UNVERIFIED SOURCE AUTHORITY**; earlier files exist, but matching original native summary serializer is not available in that tree, and no v180 code is invented |
| Versions v472–490 | Earlier/later source boundary | **NOT VERIFIED**; no matching original example found in the reviewed source corpus; do not extrapolate |
| Later Xbox 360 UDK LZX | General UE3 reader's independent LZX path | **KNOWN ORIGINAL FAILURE** on v813 and v820 Xbox fixtures; original XMem codec/original uncompressed output not established. Do not assume PC LZO success resolves this |
| Full UE3 export DependsMap, later GUID/thumbnail auxiliary structures | Source-only UE3 format document defines these; current consumer focuses on summary/name/import/export | **PARTIAL GENERAL UE3 FORMAT COVERAGE**; not a demonstrated defect in UT3's currently exposed metadata paths |
| Compiled UT3 native game loaders across seven EXEs | Seven original `UT3.exe` binaries available, separately SHA-pinned | **NOT NATIVE-RUNTIME CERTIFIED**; no CDB/REA exact compiled method-output oracle performed in this batch |

### Closed vs. still open

**Closed within this batch:** all 90 distinct recovered UT3 patch script-package layouts and their 1,080 independent raw-summary values; patch-source class introduction boundary; source-aware early UE3 v402–429 independent original format reading; representative later UE3 source revision metadata v491–867 for 38 observed version values.

**Remaining substantive gaps:** early UE3 variants without source (v180–186), unrepresented v472–490, Xbox XMem LZX decompression, complete static interpretation of later auxiliary directories, exact original-native compressed logical-byte differential, and full runtime mount/provider behaviour without an observed mount environment.

The last group is **not** to be reclassified PASS merely to close a checklist. It requires a genuine format-dependent consumer case, matching original code/binary oracle and a bounded reproducible output. No unrelated asset renderer, class-native payload loader or game-play logic is in scope.

## Proof, files and non-destructive execution

All original patch/game/engine binaries stayed in place; the previously retained read-only extracted native archive is at `D:\Temp\UnrealDB\ue3\native-version-archive`. Archive provenance and every original SHA-256 are recorded in `manifest.json`. This batch used one deduplicating PHP reader pass and **two independent Python summary readers** plus one early-family source-selected Python table reader. No source packages or executables were modified, and no native game binary was launched.

**Durable new source/version evidence:** `tests/evidence/ue3/ue3-original-native-patch-version-cohorts-20261106.json`.

**Earlier retained evidence (unchanged):** `tests/evidence/ue3/ue3-original-ut3-v512-all-six-metadata-codepaths-20261102.json`, `tests/evidence/ue3/ut3-patch5-original-package-hash-differential-20261105.json`, and `tests/evidence/ue3/ue3-udk-original-corpus-20261105.json`.

The two independent source-only technical documents are issued separately as **edition 1.2 Word and Markdown artifacts**. They do not cite implementation-specific tools, databases, tests or product claims.

**Changes:** verification/reporting only. Zero UnrealDB production PHP edits, zero PRD SQL writes, zero UEDB5 source rescans/replacements, zero changes to Epic source, zero newly created test-contract IDs.
