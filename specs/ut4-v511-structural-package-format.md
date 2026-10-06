# Unreal Tournament 4 explicit UE4 v511 structural package format

## Scope

This specification covers **explicitly versioned UT4 package files whose serialized UE4 package version is 511**.

It is deliberately limited to the package metadata UnrealDB consumes:

- `FPackageFileSummary`;
- NameMap / `FNameEntrySerialized`;
- ImportMap / `FObjectImport`;
- ExportMap / `FObjectExport`;
- string asset references;
- preload-dependency summary fields and table.

It does **not** claim that the exact UT/Main v511 runtime or `VerifyImport` implementation is available.

## Why a separate source policy is required

The available UT4 source authorities do not contain a package-version-511 engine state:

- UT/Main merge commit `89f90b867d` carrying CL 3228288 has automatic package version **509**.
- UT4 clean-master `cc3df7642980e2541fe2d7842f0782bae6c70cd5` ends at **510** with `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES`.
- The UT4 repository contains no `VER_UE4_SKYLIGHT_MOBILE_IRRADIANCE_MAP` and no corresponding `USkyLightComponent::Serialize` implementation.

Original UT4 package bytes nevertheless prove that a distinct population is genuinely serialized with package version **511**. These are not database/version-assumption artifacts.

Therefore v511 must not be mislabeled as clean-master v510 and must not inherit clean-master `VerifyImport`.

## Exact Epic structural authority

The authoritative Epic commit that introduces package version 511 is:

- repository: `ardenee/UnrealEngine` / Epic UE4 history;
- commit: `ae727f8dabbec201963ce68d12aed0a7da6fab91`;
- parent: `6744552b76c61394f4dd0eb34557ccd257ec6f4c`;
- date: 2017-04-06;
- merge source: `//UE4/Dev-Mobile @ 3383462`;
- version added: `VER_UE4_SKYLIGHT_MOBILE_IRRADIANCE_MAP = 511`;
- underlying work includes UE-42436 / CL 3365564.

The v511 change adds `USkyLightComponent::Serialize()` and serializes `IrradianceEnvironmentMap` in object payload data.

UnrealDB does not deserialize that object payload.

## Proven package-table equivalence at 510 -> 511

The following files have identical Git blob hashes between Epic parent `6744552b...` (v510) and v511 commit `ae727f8d...`:

- `Engine/Source/Runtime/CoreUObject/Public/UObject/PackageFileSummary.h`
- `Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp`
- `Engine/Source/Runtime/CoreUObject/Public/UObject/ObjectResource.h`
- `Engine/Source/Runtime/CoreUObject/Private/UObject/ObjectResource.cpp`
- `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`
- `Engine/Source/Runtime/Core/Public/UObject/NameTypes.h`
- `Engine/Source/Runtime/Core/Private/UObject/UnrealNames.cpp`

Therefore package version 511 does not change any serialized package/table structure used by UnrealDB.

UT4 clean-master was separately compared against these serialization entry points. Its relevant serialized field order and gates are equivalent for this metadata boundary:

- NameMap uses the same `FNameEntrySerialized` representation and v503 hashes;
- `FObjectImport` serializes `ClassPackage`, `ClassName`, `OuterIndex`, `ObjectName`;
- `FObjectExport` uses the same v510 64-bit `SerialSize`/`SerialOffset` representation;
- export flags/package identity/preload fields occupy the same serialized slots;
- preload dependencies are serialized as the same `FPackageIndex` table.

Branch-local implementation differences that do not alter these bytes are not treated as package-format differences.

## UnrealDB source policy

Explicit serialized v511/licensee-0 UT4 packages use:

`ue4-epic-dev-main-ae727f8d-v511-ut4-structural-package`

This policy means only:

> the serialized package/table metadata required by UEDB5 is source-backed for v511.

It does **not** mean:

- the exact missing UT/Main producer source has been recovered;
- clean-master v510 `VerifyImport` applies;
- final UE4.27.2 `VerifyImport` applies;
- later UE4 package fields may be projected backward.

Unversioned UT4 packages remain clean-master v510 packages and use:

`ue4-ut4-clean-master-v510-classic-package`

with assumed parser version 510.

## Dependency / VerifyImport boundary

The v511 structural policy has **no VerifyImport resolver profile**.

Any v511 import requiring object-level verification remains:

`ue4_verify_import_source_implementation_unavailable`

until the exact UT/Main v511 linker implementation is recovered or independently source-proven.

Physical package absence can still be represented using source-independent provider evidence where applicable, but object matching must not inherit v510 or later UE4 behavior.

## Migration consequences

Previously staged explicit v511 rows do not require original package rereading solely for this structural-policy correction because:

1. their raw serialized version is genuinely 511;
2. the v510->v511 package/table serializers are source-proven unchanged;
3. the old reader already used the v510 64-bit export layout at v511.

They may receive a metadata-only source-policy refresh after the staged summary proves:

- `unversioned = false`;
- package version = 511;
- licensee version = 0;
- staged schema is the UT4 UEDB5 schema.

Old unversioned rows whose catalogue version also appears as 511 are **not** eligible for that refresh. Their staged summary identifies them as unversioned and they require Pass-1 reread with assumed v510.

The bounded diagnostic must therefore inspect v511 staged summaries before classifying them.

## Regression requirements

At minimum:

- `verify-uedb5-ut4-persistence.php` must prove explicit v511 builds successfully under the separate structural policy and retains the v510 table layout;
- `verify-uedb5-unreal-classic-dependency-resolution.php` must prove the v511 structural policy does not gain the clean-master VerifyImport profile;
- the metadata-only refresh contract must prove v511 gets the v511 structural policy and unversioned rows remain blocked.
