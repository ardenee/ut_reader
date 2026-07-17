# Mod and Dependency Package Exports

UnrealDB can build a download package from a selected catalog file and its resolved dependency graph.

## Output formats

| Target | Output |
|---|---|
| Unreal Tournament / UT99 | `.umod` |
| Unreal Tournament 2003 | `.ut2mod` |
| Unreal Tournament 2004 | `.ut4mod` |
| Unreal Tournament 3 PC | Structured `.zip` containing `UTGame/...` paths |
| Unreal Tournament pre-alpha / UT4 | Uncompressed, unencrypted UE4 `.pak` |
| Other profiles | Dependency `.zip` |

The UMOD-family outputs share Epic's archive container and generated `Manifest.ini` data while using the extension expected for the selected game.

## Download flow

Open a file's **Download** page and use **Create mod/dependency package**.

The form allows the user to select an enabled format and enter:

- package name;
- version;
- author;
- whether resolved dependencies should be included;
- whether an incomplete package may be generated, when the administrator allows that option.

The package builder calculates the dependency closure before writing the output. The dependency walk can be configured as direct-only or transitive.

## Safety rules

Generated packages:

- remain inside the selected game's catalog;
- reject a protected base-game file as the selected root;
- exclude protected base-game dependencies from redistribution;
- stop on missing and package-only dependencies by default;
- enforce configurable file-count and payload-size limits;
- reject duplicate destination paths;
- use streamed file copying rather than loading every payload into PHP memory;
- reopen and validate the completed archive before serving it.

`UnrealDB-Mod.json` records the selected file, game/profile, file hashes, GUIDs, destination paths, excluded base-game files, and unresolved dependencies.

## Destination paths

The exporter prefers a recorded `ue_file_locations.source_relative_path` because catalog storage filenames are hash-based and do not preserve the original game folder.

When a usable source path is unavailable:

- UE1/UE2 packages use extension-based standard folders such as `System`, `Maps`, `Textures`, `Sounds`, `Music`, `StaticMeshes`, and `Animations`;
- UT3 packages fall back to `UTGame/Published/CookedPC/CustomMaps` for cooked content;
- general dependency ZIPs use the same game-folder inference;
- UT4 PAK export does **not** accept inferred paths. Every UT4 asset must have a recorded path beneath the game's `Content` tree.

Re-scan local source folders before creating UT4 PAK files so `.uasset`, `.uexp`, `.ubulk`, map, and related sidecar paths are preserved correctly.

## UT4 PAK scope

The built-in writer currently creates:

- PAK version 3;
- uncompressed entries;
- no encryption;
- no index encryption;
- per-entry SHA-1 hashes;
- a configurable mount point, defaulting to `../../../UnrealTournament/Content/`.

The generated PAK is structurally reopened and verified by UnrealDB. Live-game compatibility should still be confirmed with representative UT4 fixtures before broad public use.

## Administration

Open **Downloads → Package Export Settings** to configure:

- global package generation;
- individual exporter families;
- transitive dependency traversal;
- incomplete-package permission;
- maximum file count;
- maximum payload size;
- default author;
- UT4 mount point;
- per-game default format overrides.

Generated packages require local catalog payloads. They are unavailable when public download mode is `external_mirror` or `disabled`. `external_mirror_preferred` continues to permit generated packages from local storage.

## Validation

`catalog/tests/mod-package-format-test.php` builds and reopens representative UMOD-family and PAK outputs. The catalog quality workflow runs this test alongside PHP syntax checks.

The test confirms container structure and hashes; it does not replace fixture testing in the original games.
