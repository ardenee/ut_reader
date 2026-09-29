# Unreal Tournament 3 package-version-512 dependency resolution

## Scope and revision

This specification documents deterministic import/dependency resolution for Unreal Tournament 3 packages using UE3 package version 512.

Authoritative source used for this audit:

- Source tree: `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\epic.jan2008\UnrealEngine3`
- Core source: `Development/Src/Core`
- `UnObjVer.h` defines package version 512 as `VER_FULL_VERSION_OF_UT3_BUMP`.

The later `ue3-udkultimate-dependency-resolution.md` remains the authority for the supplied UDKUltimate tree. Later UE3 behavior must not be back-ported to UT3 merely because both are UE3.

## Serialized identity

`FObjectImport` serializes `ClassPackage`, `ClassName`, `OuterIndex`, then `ObjectName`.

`FObjectExport` serializes, in order, `ClassIndex`, `SuperIndex`, `OuterIndex`, `ObjectName`, `ArchetypeIndex`, `ObjectFlags`, `SerialSize`, `SerialOffset`, `ComponentMap`, `ExportFlags`, `GenerationNetObjectCount`, `PackageGuid`, and `PackageFlags`.

UT3 package indices are signed 32-bit `PACKAGE_INDEX` values. Zero is the root package outer; negative values address imports; positive values address exports.

## FName numbering

UT3 `FName` stores the internal number. `UnName.h` defines:

- `NAME_NO_NUMBER_INTERNAL = 0`
- `NAME_INTERNAL_TO_EXTERNAL(x) = x - 1`

Therefore a serialized internal number of `1` renders as suffix `_0`, not `_1`. UnrealDB must apply this when converting serialized FNames to text.

## Import-map fixups before matching

January 2008 `ULinkerLoad::FixupImportMap()` performs these fixed engine remaps before import verification:

1. `Engine.SoundCueLocalized` class references become `SoundCue` where the source conditions match.
2. Imports whose class is `Engine.SoundCueLocalized` use class name `SoundCue`.
3. The old top-level `SequenceObjects` package import becomes `Engine`.
4. `ClassPackage == SequenceObjects` becomes `Engine`.

These are engine rules, not configurable INI `ClassRemap` behavior. Dependency package identity and common-package classification must use the post-fixup root.

## No later RemapClasses pass for UT3 version 512

The January 2008 linker has `FixupImportMap()` but does not contain the later `ULinkerLoad::RemapClasses()` pass.

Accordingly, the later pre-`VER_FIXED_PREFAB_SEQUENCES` (536) prefab class rewrite documented for later UE3/UDK must not be applied to UT3 version-512 packages.

This is a revision boundary, not a generic `version < 536` rule.

## Export class identity

For an export whose `ClassIndex` is an import, January 2008 `GetExportClassPackage()` requires that class import's `OuterIndex` to be another import and returns that outer import's `ObjectName` as the class package.

The later UE3 behavior that can obtain the class package from an export outer is not present in the UT3-512 source and must not be used for game 6.

For a positive `ClassIndex`, the class is an export in the same linker and the package is the linker's package. A zero/UClass index yields the `Core.Class` identity as defined by the source helper.

## VerifyImportInner provider matching

A top-level import (`OuterIndex == ROOTPACKAGE_INDEX`) must be exactly `Core.Package`. Its `ObjectName` identifies the package whose linker is loaded.

For an ordinary non-root import, the outer import is verified first and its `SourceLinker` is copied to the child. Provider exports are then matched by exact:

- `ObjectName`
- export class name == import `ClassName`
- export class package == import `ClassPackage`

The resolved outer must also agree. If the outer import has no `SourceIndex`, the candidate export must be root-level. Otherwise `OuterImport.SourceIndex + 1` must equal the candidate export's `OuterIndex`.

An otherwise matching provider export must have `RF_Public` for normal external loading. A same-name private export is not interchangeable with a public provider export.

For cooked packages whose import outer is an export, this source returns with an Epic TODO rather than inventing the ordinary provider-linker path. UnrealDB must preserve that unresolved behavior.

Catalog classification rule: this source return is **unresolved**, not proof that the required package/object is missing. UnrealDB records these imports (and descendants whose import-outer ancestry reaches one) as source-unresolved and excludes them from missing-dependency counts.

## VerifyImport wrapper and redirectors

`VerifyImport()` first calls `VerifyImportInner()`. If the package linker exists but the requested non-root object is absent, it can retry as `Core.ObjectRedirector` and then load/preload the redirector to obtain `DestinationObject`.

UnrealDB may only reproduce this when the redirector's serialized destination can actually be decoded. A loose same-name or same-path fallback is not source-equivalent.

## UnrealDB implementation invariants

1. Provider grouping uses the post-`FixupImportMap()` root package.
2. One physical provider/linker is evaluated at a time; exports from different physical files are never combined.
3. `VerifyImport` recursion requires the consumer's complete ImportMap even when a maintenance job intends to rewrite only selected dependency rows.
4. Internal lookup keys must remain strings; numeric-looking package names must not be allowed to become integer PHP array keys.
5. UE3 export projections used for exact matching are incomplete if class-package identity, class-name identity, `OuterIndex`, or `ObjectFlags` are unavailable.
6. Derived catalog paths are display/search identity, not a prerequisite for `VerifyImportInner` candidate matching.
7. Runtime-only native/transient/editor recovery must not be fabricated from package metadata.

## Source boundary

These rules are specifically proven for UT3 package version 512 from the January 2008 source tree. Later UE3 revisions may differ in class remapping and export-class-package derivation and must be audited against their own source before sharing this policy.

## RF_Public and 64-bit ObjectFlags

UT3 `EObjectFlags` are 64-bit. In the January 2008 `Core/Inc/UnObjBas.h` source:

- `RF_Public = 0x0000000400000000`

`FObjectExport::ObjectFlags` is serialized as the 64-bit value and `VerifyImportInner()` tests that exact flag when deciding whether a matching provider export is externally visible.

UnrealDB must therefore preserve the complete serialized QWORD. Keeping only the low 32 bits is not a harmless representation change: it removes `RF_Public` entirely and causes public UT3 exports to be rejected as private.

The SQL `ue_export_path_lookup.object_flags` projection is `BIGINT UNSIGNED`, so it can store the required value. Existing metadata created by a reader that discarded the high 32 bits cannot be repaired from that truncated metadata alone; the authoritative package bytes must be reparsed before dependency rows are rebuilt.
