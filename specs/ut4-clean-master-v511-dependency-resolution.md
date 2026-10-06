# Unreal Tournament 4 clean-master dependency and VerifyImport resolution

## Scope and source authority

This is the normative UT4 dependency contract.

Authority:

- source tree: `L:\Source\Games\UT4\UnrealTournament`
- clean-master commit `cc3df7642980e2541fe2d7842f0782bae6c70cd5`
- `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`
- package version 214-511, licensee 0

Final UE4.27.2 is a later generic comparison and must not replace the clean-master implementation.

## Provider selection

UT4 v511 predates serialized `FObjectImport::PackageName`.

A top-level Package import establishes/loads the provider from its `ObjectName`. Child imports use the outer Import's `SourceLinker`.

UnrealDB chooses one physical provider first. Provider content must not be used to choose among duplicate physical files.

## File-backed export match

The deterministic file-backed match requires source-equivalent agreement on:

- ObjectName;
- ClassName;
- ClassPackage;
- expected outer export/root;
- public visibility.

Clean-master first determines whether a full ClassPackage match exists. Short package-name fallback is considered only when no full package-name match exists.

## Import outer boundary

For a non-null import outer, clean-master asserts that the outer is another Import.

A positive Export outer is therefore a source assert boundary rather than a cue to borrow later UE4 PackageName behavior.

## Private export behavior

A private export is never an unconditional static match.

Clean-master initializes `SafeReplace` from editor/commandlet runtime state. Direct references from serialized import/export graph state can force `SafeReplace` false.

Therefore:

- directly hard-referenced private candidate: deterministic rejection;
- otherwise: editor/runtime-context unresolved;
- never promote the candidate to an exact static dependency.

Later UE4.27.2 recursive `IsPrivateImportAllowed` containment predicates are not present in this clean-master implementation.

## File-backed misses and runtime fallback

An established provider with no deterministic public export match is not automatically a hard missing dependency.

Clean-master can still depend on runtime state including native/transient objects, `LOAD_FindIfFail`, CDO/class lookup, already-loaded objects and SafeReplace/editor state.

Static UnrealDB records those residual branches unresolved. A genuinely absent physical provider remains hard missing.

## ObjectRedirector retry

The wrapper can retry as `ObjectRedirector` and preload its destination.

Destination payload/class validation requires runtime object state not available from package tables alone, so those cases remain unresolved unless source-required destination evidence is available.

## Runtime/config fixups

`FixupImportMap`, `RemapImports` and `FixupExportMap` consume live redirect/plugin/game configuration.

UnrealDB does not invent those maps.

## UnrealDB profile and fail-closed boundary

All current/V4 and UEDB5 dependency entry points use:

`PROFILE_UT4_CLEAN_MASTER = ue4-ut4-clean-master-v511`

only when:

- game source is UT4;
- package version is 214-511;
- licensee version is 0;
- staged source policy is canonical `ue4-ut4-clean-master-v511-classic-package` for V5.

Explicit v511 is part of clean-master and receives the same clean-master VerifyImport implementation.

v512+ or nonzero-licensee rows fail closed as source implementation unavailable.

## Migration policy

Section 4G uses dependency policy `uedb5-dependency-pass-v12`.

The corrected transition distinguishes:

- faulty staged policy `ue4-ut4-clean-master-v510-classic-package`: mandatory Pass-1 reread from original bytes;
- original legacy `ue4-4.27.2-release-classic-package`: package tables were produced with the old source-correct numeric gates; metadata-only source-policy refresh is sufficient;
- erroneous `ue4-epic-dev-main-ae727f8d-v511-ut4-structural-package`, if encountered: migration alias only; refresh to canonical clean-master v511;
- canonical `ue4-ut4-clean-master-v511-classic-package`: normal UT4 v511 source behavior.

Object-level dependency rows whose VerifyImport outcome can change are rebuilt in Pass 2 after source-policy repair/refresh.

No whole-game original-byte reread is justified. Only rows actually written under the faulty v510 policy require byte rereading.
