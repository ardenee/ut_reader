# Unreal Tournament 4 clean-master UE4 package format

## Scope and source authority

This is the normative package-serialization and pre-dependency contract for Unreal Tournament 4.

Primary authority:

- source tree: `L:\Source\Games\UT4\UnrealTournament`
- branch: `clean-master`
- commit: `cc3df7642980e2541fe2d7842f0782bae6c70cd5`
- relevant source:
  - `Engine/Source/Runtime/Core/Public/UObject/ObjectVersion.h`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/ObjectResource.cpp`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/Linker.cpp`

Final UE4.27.2 is supplemental comparison only. It is not UT4 game authority.

## Enum-numbering rule

`EUnrealEngineObjectUE4Version` must be counted as a C++ enum, not by filtering member names.

In particular, the UT4/UE4 enum contains the real typoed member:

`VAR_UE4_ARRAY_PROPERTY_INNER_TAGS`

It increments every later enum value even though its identifier does not start with `VER_UE4_`.

Older source also contains other nonstandard members such as `VER_DEBUG_MATERIALSHADER_UNIFORM_EXPRESSIONS`. Any audit that counts only identifiers matching `VER_UE4_*` is invalid.

This rule corrects the earlier 4G audit that derived many gates one version too low.

## Exact package-version boundary

UT4 clean-master defines:

- `VER_UE4_OLDEST_LOADABLE_PACKAGE = 214`
- `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES = 511`
- `VER_UE4_AUTOMATIC_VERSION_PLUS_ONE = 512`
- therefore `VER_UE4_AUTOMATIC_VERSION = 511`
- `GPackageFileUE4Version = 511`
- licensee version: 0

Unversioned UT4 packages therefore load with effective UE4 version **511**.

Final UE4.27.2 continues the enum history and has:

- `VER_UE4_AUTOMATIC_VERSION_PLUS_ONE = 523`
- therefore final `VER_UE4_AUTOMATIC_VERSION = 522`.

## Exact shared UE4 version gates

The shared UnrealDB UE4 reader uses these exact source values:

| Gate | Version |
|---|---:|
| `VER_UE4_WORLD_LEVEL_INFO` | 224 |
| `VER_UE4_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE` | 278 |
| `VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS` | 326 |
| `VER_UE4_ENGINE_VERSION_OBJECT` | 336 |
| `VER_UE4_LOAD_FOR_EDITOR_GAME` | 365 |
| `VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP` | 384 |
| `VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION` | 444 |
| `VER_UE4_SERIALIZE_TEXT_IN_PACKAGES` | 459 |
| `VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT` | 485 |
| `VER_UE4_NAME_HASHES_SERIALIZED` | 504 |
| `VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS` | 507 |
| `VER_UE4_TemplateIndex_IN_COOKED_EXPORTS` | 508 |
| `VER_UE4_ADDED_SEARCHABLE_NAMES` | 510 |
| `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES` | 511 |

Later final-UE4 gates include:

- `VER_UE4_ADDED_SOFT_OBJECT_PATH = 514`
- `VER_UE4_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID = 516`
- `VER_UE4_ADDED_PACKAGE_OWNER = 518`
- `VER_UE4_NON_OUTER_PACKAGE_IMPORT = 520`
- final UE4.27.2 latest = 522.

## Import layout boundary

UT4 v511 predates `VER_UE4_NON_OUTER_PACKAGE_IMPORT`.

UT4 `FObjectImport` therefore does not serialize the later explicit `PackageName` field. Provider identity is derived from the classic Import/Outer graph and top-level Package import.

Do not project final UE4.27.2 PackageName behavior backward into UT4.

## Export serial width

`FObjectExport::SerialSize` and `SerialOffset` switch to 64-bit serialization at **v511**.

- v510: 32-bit serial size/offset.
- v511: 64-bit serial size/offset.

Regression coverage must exercise both sides of this boundary.

## Loader ordering before VerifyImport

Clean-master loads the raw tables and then performs source preprocessing/fixups before final verification. Relevant ordering includes:

1. NameMap serialization
2. ImportMap serialization
3. ExportMap serialization
4. `FixupImportMap`
5. `RemapImports`
6. `FixupExportMap`
7. final import verification/load work

The fixup/remap stages consume runtime/config state such as redirects, plugin/game-name maps and editor/runtime state. UnrealDB preserves serialized identity and does not fabricate runtime mappings.

## UnrealDB source profile

Canonical UT4 UEDB5 snapshots use:

`ue4-ut4-clean-master-v511-classic-package`

The canonical source-backed profile accepts:

- package version 214-511;
- licensee version 0;
- unversioned 0/0 packages interpreted with assumed version 511.

The following are migration-only legacy identifiers and are not source policies for new output:

- `ue4-4.27.2-release-classic-package`
- `ue4-ut4-clean-master-v510-classic-package`
- `ue4-epic-dev-main-ae727f8d-v511-ut4-structural-package`

## 4G audit correction and staged-data repair

The earlier 4G audit accidentally ignored `VAR_UE4_ARRAY_PROPERTY_INNER_TAGS` when deriving numeric enum values. It therefore shifted many gates one lower, treated clean-master latest as 510, assumed unversioned UT4 at 510, and incorrectly split explicit v511 into a separate structural policy.

Those conclusions are withdrawn. See `ut4-version-enum-audit-correction.md`.

The old pre-4G reader used the correct numeric gates. Therefore original staged rows still carrying `ue4-4.27.2-release-classic-package` do not require byte rereading solely because of this correction.

Rows carrying `ue4-ut4-clean-master-v510-classic-package` were written by the faulty 4G remediation and must be reread from original verified bytes. The exact repair tool is:

`C:\php8.5\php.exe catalog\bin\repair-ut4-v511-pass1.php`

It is read-only unless `--apply` is supplied and selects only the faulty v510 policy.

The superseded command `reparse-ut4-v510-pass1.php` is deliberately disabled.

After the bad-policy repair reaches zero, remaining source-correct legacy rows can be relabeled to the canonical v511 policy using a separately verified metadata-only transition.

No live V4 `ue_files.package_version` mutation is required during staging.
