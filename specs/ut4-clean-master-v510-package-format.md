# Unreal Tournament 4 clean-master UE4 package format

## Scope and source authority

This specification is the game-specific package/LinkerLoad contract for Unreal Tournament 4.

Primary authority:

- source tree: `L:\Source\Games\UT4\UnrealTournament`
- branch: `clean-master`
- commit: `cc3df7642980e2541fe2d7842f0782bae6c70cd5`
- relevant sources:
  - `Engine/Source/Runtime/Core/Public/UObject/ObjectVersion.h`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/ObjectResource.cpp`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`
  - `Engine/Source/Runtime/CoreUObject/Private/UObject/Linker.cpp`

Final UE4.27.2 is supplemental comparison only. It must not be used as UT4 game authority.

## Exact package-version boundary

UT4 clean-master defines:

- `VER_UE4_OLDEST_LOADABLE_PACKAGE = 214`
- `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES = 510`
- `VER_UE4_AUTOMATIC_VERSION_PLUS_ONE = 511`
- therefore `VER_UE4_AUTOMATIC_VERSION = 510`
- licensee version: 0

Unversioned UT4 packages therefore load with effective UE4 version **510**, not 521/522.

Final UE4.27.2 continues the same enum history after UT4 and adds versions 511-521. Its final `VER_UE4_AUTOMATIC_VERSION` is 521.

UT4 content in the catalogue also contains genuine **explicit v511** packages. That population is covered separately by `ut4-v511-structural-package-format.md`; it is not reclassified as clean-master v510.

## Exact shared UE4 version gates

The shared UnrealDB UE4 reader must use the actual UE4 enum values:

| Gate | Version |
|---|---:|
| `VER_UE4_WORLD_LEVEL_INFO` | 224 |
| `VER_UE4_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE` | 278 |
| `VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS` | 325 |
| `VER_UE4_ENGINE_VERSION_OBJECT` | 335 |
| `VER_UE4_LOAD_FOR_EDITOR_GAME` | 364 |
| `VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP` | 383 |
| `VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION` | 443 |
| `VER_UE4_SERIALIZE_TEXT_IN_PACKAGES` | 458 |
| `VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT` | 484 |
| `VER_UE4_NAME_HASHES_SERIALIZED` | 503 |
| `VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS` | 506 |
| `VER_UE4_TemplateIndex_IN_COOKED_EXPORTS` | 507 |
| `VER_UE4_ADDED_SEARCHABLE_NAMES` | 509 |
| `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES` | 510 |

Later final-UE4 gates are not part of UT4 clean-master:

- `VER_UE4_ADDED_SOFT_OBJECT_PATH = 513`
- `VER_UE4_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID = 515`
- `VER_UE4_ADDED_PACKAGE_OWNER = 517`
- `VER_UE4_NON_OUTER_PACKAGE_IMPORT = 519`
- final UE4.27.2 latest = 521

The previous UnrealDB reader values were one too high for many gates. Those values were corrected against `ObjectVersion.h`.

## Import layout boundary

UT4 v510 predates `VER_UE4_NON_OUTER_PACKAGE_IMPORT`.

Therefore UT4 `FObjectImport` does **not** serialize the later explicit `PackageName` field. Provider identity is derived from the classic import/outer graph and top-level Package import.

Do not project UE4.27.2 PackageName behavior backward into UT4.

## Export serial width

At v510, `FObjectExport::SerialSize` and `SerialOffset` use the 64-bit serialization path.

v509 uses the preceding 32-bit path.

Regression coverage must exercise both sides of this exact boundary.

## Loader ordering before VerifyImport

Clean-master loads the serialized tables and then performs source preprocessing/fixups before final import verification. The relevant sequence includes:

1. NameMap serialization
2. ImportMap serialization
3. ExportMap serialization
4. `FixupImportMap`
5. `RemapImports`
6. `FixupExportMap`
7. final import verification/load work

The fixup/remap stages consume runtime/config state such as active redirects, plugin/game-name maps and editor/runtime mode. They are not raw serialized package format.

UnrealDB therefore preserves the source identity in staged metadata and does not fabricate runtime redirect configuration that was not supplied.

## UnrealDB source profile

UT4 UEDB5 snapshots use:

`ue4-ut4-clean-master-v510-classic-package`

The source-backed game profile is accepted only for:

- package version 214-510;
- licensee version 0;
- unversioned packages interpreted with assumed version 510.

Anything outside that clean-master boundary does not inherit the clean-master source policy. Explicit v511/licensee-0 packages may use the separate structural-only policy documented in `ut4-v511-structural-package-format.md`; v512+ or nonzero-licensee packages remain outside the source-backed UT4 package boundary.

## Migration impact of the corrected reader gates

Because the previous reader gates were one version too high, an explicitly versioned package is at layout risk only when it is exactly on a gate whose threshold changed:

`325, 335, 364, 383, 443, 458, 484, 503, 506, 507, 509, 510`

Those exact files require Pass-1 reread from original package bytes.

Unversioned UT4 packages previously staged with the old assumed version also require Pass-1 reread using v510.

Explicit v214-510/licensee-0 packages that are not on a changed reader gate can refresh the UEDB5 source-policy label from staged metadata without reopening the original package.

Explicit v511/licensee-0 packages can also receive metadata-only refresh **only** after their staged summary proves they are not unversioned. Their destination policy is `ue4-epic-dev-main-ae727f8d-v511-ut4-structural-package`; they do not become clean-master v510 dependencies and are not Pass-2 VerifyImport candidates.

Use:

`D:\php8.5\php.exe catalog\bin\diagnose-ut4-v510-impact.php --summary`

Then run without `--summary` for exact IDs.

Metadata-only eligible rows can be refreshed with:

`D:\php8.5\php.exe catalog\bin\refresh-ut4-v510-source-policy.php`

The refresh tool is read-only unless `--apply` is supplied and refuses gate/unversioned/identity-mismatched rows.

Both tools fail closed when the database and staged UEDB5 storage do not correspond.
