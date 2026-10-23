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
