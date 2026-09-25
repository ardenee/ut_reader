# UT4MOD / Unreal Tournament 4 custom-content format

## 1. Scope

This specification records what the supplied official Unreal Tournament (UE4-era) source actually implements for user-created UT4 content.

Primary game source:

- repository: `ardenee/UnrealTournament`
- branch: `clean-master`
- `UnrealTournament/Plugins/PackageContent/Source/Private/PackageContent.cpp`
- `UnrealTournament/Build/Scripts/UnrealTournamentProto.Automation.cs`

Underlying engine source used by that game tree:

- UE4 PakFile runtime
- UnrealPak
- AutomationTool staging/pak pipeline

Later engine comparison:

- repository: `ardenee/UnrealEngine4`
- 4.27.2-release lineage
- PakFile/UnrealPak implementation is relevant to the physical PAK format, but does not establish a UT4MOD wrapper.

## 2. Source-backed result

The supplied UT4 source does **not** define a physical `.ut4mod` container, UT4MOD magic, UT4MOD header, UT4MOD directory, or UT4MOD reader.

Official UT custom-content publishing produces a normal UE4 `.pak` file.

Therefore UnrealDB must not invent a UT4MOD binary format or treat `.ut4mod` as a source-proven wrapper around PAK data.

If files named `.ut4mod` exist in third-party collections, their physical interpretation remains unresolved by the supplied official source until an authoritative producer/consumer implementation is available.

## 3. Official UT4 publishing path

The UT editor's `PackageContent` plugin exposes publishing for:

- levels;
- weapons;
- cosmetic hats;
- characters;
- taunts;
- mutators;
- crosshairs.

All these paths ultimately call:

```text
PackageDLC(DLCName, ...)
```

which launches AutomationTool using:

```text
makeUTDLC
-DLCName=<name>
-platform=<Win64|Linux|Mac>
-version=<network version>
```

Thus the official custom-content path is DLC cooking/staging, not a UMOD-style module writer.

## 4. MakeUTDLC

`UnrealTournamentProto.Automation.cs` implements `MakeUTDLC`.

Its project parameters enable both cooking and PAK generation:

```text
Cook = true
Pak  = true
BasedOnReleaseVersion = <release asset registry>
StageDirectory = UnrealTournament/Saved/StagedBuilds/<DLCName>
```

The command cooks content from:

```text
UnrealTournament/Plugins/<DLCName>/Content
```

using the DLC name and the selected target platform.

## 5. Version metadata

During cooking, MakeUTDLC writes:

```text
UnrealTournament/<DLCName>-version.txt
```

into the cooked output.

The contents are the `VersionString` passed to the command, which the editor supplies from:

```cpp
FNetworkVersion::GetLocalNetworkVersion()
```

The command's fallback when no `-version` argument is supplied is `NOVERSION`.

This version text file is content inside the staged DLC/PAK; it is not a PAK header field and not a UT4MOD wrapper header.

## 6. Asset registry metadata

The DLC cook produces an `AssetRegistry.bin`.

MakeUTDLC renames it to:

```text
<DLCName>-AssetRegistry.bin
```

before staging.

Again this is a file carried in the staged PAK content, not a separate outer format.

## 7. Staging and final PAK

MakeUTDLC stages the cooked DLC as UFS content and invokes the normal UE4 staging/PAK pipeline.

After PAK creation it removes any pre-existing:

```text
<DLCName>-<CookPlatform>.pak
```

and renames the generated:

```text
UnrealTournament-<CookPlatform>.pak
```

to:

```text
<DLCName>-<CookPlatform>.pak
```

The editor then expects that exact platform PAK beneath:

```text
Saved/StagedBuilds/<DLCName>/<Platform>/UnrealTournament/Content/Paks/
```

For example on Windows:

```text
<DLCName>-WindowsNoEditor.pak
```

## 8. Installed local custom-content location

After a successful publish, the editor copies the resulting PAK to the user's UT custom-content area.

Windows:

```text
<UserDir>/<GameName>/Saved/Paks/MyContent/<DLCName>-WindowsNoEditor.pak
```

Linux:

```text
<UserDir>/<GameName>/Saved/Paks/MyContent/<DLCName>-LinuxNoEditor.pak
```

Mac:

```text
<UserDir>/<GameName>/Saved/Paks/MyContent/<DLCName>-MacNoEditor.pak
```

This is explicit source evidence that the locally consumed custom-content artifact is a `.pak`.

## 9. Sharing/upload

After packaging, the editor asks whether the user wants to share the content.

If accepted, it launches the Epic Games Launcher with:

```text
-assetuploadcategory=ut
-assetuploadpath="<PakPath>"
```

The uploaded path is the generated PAK itself.

There is no intervening UT4MOD builder in this official path.

## 10. Comparison with UMOD/UT2MOD

This is a major generational change.

UE1/UE2 module systems use the version-1 Unreal module archive documented in the UMOD/UT2MOD specifications.

UT4's supplied UE4-era game source instead uses:

```text
plugin/DLC content
    -> cook
    -> stage
    -> UE4 PAK
    -> Saved/Paks/MyContent
    -> optional Launcher upload
```

The old `FFileManagerArc` UMOD container must not be projected onto UT4.

## 11. UE4 4.27.2 cross-check

The later supplied `ardenee/UnrealEngine4` 4.27.2 lineage contains the UE4 PakFile runtime and UnrealPak tooling, confirming that PAK remains an engine archive facility.

However, generic UE4 does not by itself prove UT game-specific custom-content naming, metadata, or publishing semantics. Those rules above come from the UT game source.

Likewise, the later UE4 tree does not establish a `.ut4mod` wrapper. The physical PAK structure, version evolution, compression, index layout, signing and encryption belong in the separate PAK specifications still pending in this library.

## 12. Identification implications

For source-backed classification, UnrealDB should recognize official UT4 published custom content as PAK when the file is structurally a UE4 PAK.

Useful UT context can additionally be inferred from source-proven content such as:

- platform-style DLC PAK filename;
- `<DLCName>-version.txt`;
- `<DLCName>-AssetRegistry.bin`;
- appropriate cooked UnrealTournament paths.

These are contextual indicators, not a replacement for structural PAK validation.

A filename ending in `.ut4mod` must **not** cause UnrealDB to apply an invented parser.

## 13. What remains unresolved

The supplied official source does not prove:

- a `.ut4mod` extension registration;
- a UT4MOD magic value;
- a UT4MOD outer header;
- a UT4MOD directory structure;
- a UT4MOD installer;
- a UT4MOD-to-PAK wrapper transformation;
- a source-defined rule that renaming a PAK to `.ut4mod` makes it an official UT4MOD;
- third-party `.ut4mod` conventions.

Those points remain unresolved rather than being reconstructed from community conventions.

## 14. Relationship to pending PAK specifications

This document establishes **which container official UT4 custom-content publishing produces**.

It intentionally does not duplicate the physical PAK specification.

The following remain separate work items:

- PAK format;
- PAK compression handling;
- PAK encryption/signing handling.

Those must be derived from the applicable UE4 PakFile and UnrealPak source, including version branches, rather than summarized from the UT publishing code.

## 15. UnrealDB conformance requirements

UnrealDB should:

- not implement a fabricated UT4MOD header or magic;
- not reuse the UE1/UE2 UMOD reader for UT4;
- treat official UT4 published content as UE4 PAK data;
- preserve UT4/game context separately from physical container classification;
- use structural PAK validation rather than extension-only identification;
- recognize source-proven UT DLC metadata only as contextual evidence;
- leave unknown `.ut4mod` files unresolved unless their bytes independently match another source-proven format;
- defer compression/encryption/index details to the source-backed PAK implementation.

## 16. Source-reference matrix

| Rule | Source | Behavior proved |
|---|---|---|
| UT editor publishing feature | `UnrealTournament/Plugins/PackageContent/Source/Private/PackageContent.cpp` | Share/package UI and supported content types |
| publishing enters DLC pipeline | same, `FPackageContent::PackageDLC` | invokes `makeUTDLC` |
| target/version parameters | same | platform plus `FNetworkVersion::GetLocalNetworkVersion()` |
| final artifact expected as PAK | same, `FPackageContentCompleteTask` | builds `.../Content/Paks/<DLC>-<platform>.pak` path |
| local install/copy location | same | copies PAK into `Saved/Paks/MyContent` |
| sharing uploads PAK | same | Launcher `assetuploadpath` receives PAK path |
| DLC command implementation | `UnrealTournament/Build/Scripts/UnrealTournamentProto.Automation.cs`, `MakeUTDLC` | official UT DLC cook/stage/pak flow |
| cook source | same, `MakeUTDLC.Cook` | `Plugins/<DLCName>/Content` |
| version file | same | writes `<DLCName>-version.txt` |
| asset registry | same | renames to `<DLCName>-AssetRegistry.bin` |
| PAK creation enabled | same, `GetParams` | `Pak=true` |
| final PAK rename | same, `Cook` | `UnrealTournament-<platform>.pak` -> `<DLCName>-<platform>.pak` |
| later engine PAK implementation | `ardenee/UnrealEngine4` PakFile/UnrealPak sources | PAK remains engine archive mechanism; details deferred to PAK specs |

## 17. Result

For the supplied official Unreal Tournament UE4 source, there is **no source-backed UT4MOD binary container**.

The official custom-content artifact is:

```text
UE4 PAK
```

with UT-specific cooked DLC metadata and naming.

Accordingly, `UT4MOD` should not be implemented in UnrealDB as an independent binary format unless an authoritative source implementing such a container becomes available. If the term is retained as an ingest/category label for files encountered in the wild, it must remain distinct from the physical-format claim.
