# Unreal Tournament 4 clean-master dependency and VerifyImport resolution

## Scope and source authority

This is the UT4 game-specific dependency contract. It supersedes any earlier statement that UT4 inherits final UE4.27.2 `VerifyImportInner`.

Authority:

- `L:\Source\Games\UT4\UnrealTournament`
- clean-master commit `cc3df7642980e2541fe2d7842f0782bae6c70cd5`
- `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`
- package version 214-510, licensee 0

Final UE4.27.2 remains a later generic comparison and has materially different VerifyImport/private-import behavior.

## Provider selection

UT4 v510 predates serialized `FObjectImport::PackageName`.

A top-level Package import establishes/loads the provider from its `ObjectName`. Child imports use the outer Import's `SourceLinker`.

UnrealDB must choose one physical provider first. Provider content must not be used to choose among duplicate physical files.

## File-backed export match

The deterministic file-backed match requires source-equivalent agreement on:

- ObjectName;
- ClassName;
- ClassPackage;
- expected outer export/root;
- public visibility.

Clean-master first determines whether any full ClassPackage match exists. The short package-name fallback is only considered when no full package-name match exists.

## Import outer boundary

For a non-null import outer, clean-master asserts that the outer is another Import.

A positive Export outer is therefore outside the UT4 source contract. UnrealDB records it as a source-invalid/assert boundary instead of applying later UE4 PackageName behavior.

## Private export behavior

A private export is never an unconditional static match.

Clean-master initializes `SafeReplace` from editor/commandlet runtime state. Direct references from serialized import/export graph state can force SafeReplace false.

Therefore:

- directly hard-referenced private candidate: deterministic rejection;
- otherwise: editor/runtime-context unresolved;
- never promote the candidate to an exact static dependency.

The later UE4.27.2 recursive `IsPrivateImportAllowed` containment predicates are not present in this UT4 implementation and must not be inherited.

## File-backed misses and runtime fallback

An established provider with no deterministic public export match is not automatically a hard missing dependency.

Clean-master may still depend on runtime state including native/transient objects, `LOAD_FindIfFail`, CDO/class lookup, already loaded objects and SafeReplace/editor state.

Static UnrealDB therefore records those residual branches unresolved. A genuinely absent physical provider remains hard missing.

## ObjectRedirector retry

The wrapper can retry the requested object as an ObjectRedirector and preload its destination.

The destination object is payload/runtime state, not proven from the package tables alone. Static UnrealDB therefore records redirector candidates/descendants unresolved unless the source-required destination evidence is available.

The clean-master destination-class rule is revision-specific and must not be replaced by the later UE4.27.2 superclass-chain behavior.

## Runtime/config fixups

`FixupImportMap`, `RemapImports` and `FixupExportMap` consume live redirect/plugin/game configuration.

UnrealDB must not invent these maps. Serialized identities remain authoritative; runtime-only redirect outcomes remain unresolved when the exact runtime configuration is unavailable.

## UnrealDB profile and fail-closed boundary

All current/V4 and UEDB5 dependency entry points use:

`PROFILE_UT4_CLEAN_MASTER = ue4-ut4-clean-master-v510`

only when:

- game source is UT4;
- package version is 214-510;
- licensee version is 0.

v511+ or nonzero-licensee rows do not inherit UT4 VerifyImport. In particular, explicit v511 packages may carry the separate structural source policy `ue4-epic-dev-main-ae727f8d-v511-ut4-structural-package`, but that policy intentionally has **no** VerifyImport resolver profile because the exact UT/Main v511 linker source is unavailable.

## Migration policy

Section 4G advances the dependency policy to `uedb5-dependency-pass-v12`.

The transition reopens only the UT4 delta introduced by correcting clean-master authority and reader gates. Completed UE1/UE2/UE3/UE5 deltas are not replayed.

Potential actions are separated:

- exact changed reader-gate or old-unversioned rows: Pass-1 reread required;
- explicit non-gate v214-510 rows with legacy source policy: metadata-only source-policy refresh to the clean-master policy;
- explicit v511/licensee-0 rows whose staged summary proves `unversioned=false`: metadata-only source-policy refresh to the v511 structural policy;
- object rows whose clean-master VerifyImport outcome can differ: Pass-2 rebuild only for v214-510 clean-master packages;
- v511 object-import verification: source-profile review/fail closed until exact UT/Main v511 linker semantics are recovered;
- v512+ / nonzero-licensee rows: source-profile review/fail closed.

No whole-game original-byte reparse is justified merely by the policy rename.
