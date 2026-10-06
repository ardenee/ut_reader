# Unreal Engine 3 UDKUltimate Dependency and Import Resolution

## Scope and revision

This specification documents dependency and import resolution in the latest UE3 implementation present in the supplied source repository:

- Repository: ardenee/UE3src
- Branch: main
- Source tree: Unreal Engine [v3.0] UDKUltimate [05-11-17]/UDKUltimate
- Core source: Development/Src/Core

This document is paired with ue3-udkultimate-package-format.md. Earlier UE3 trees are revision evidence only and must not silently replace this implementation. No UE1, UE2, UE2.5, UT2003, UT2004, UT3, or UE4 behavior is assumed here.

UT3 package version 512 is documented separately in `ut3-v512-dependency-resolution.md`; its January 2008 linker differs from this later UDKUltimate implementation.

## Authoritative references

| Area | Source / symbol |
|---|---|
| package-index semantics | Core/Inc/UnLinker.h: PACKAGE_INDEX, ROOTPACKAGE_INDEX, IS_IMPORT_INDEX |
| import runtime fields | Core/Inc/UnLinker.h: FObjectImport |
| dependency representation | Core/Inc/UnLinker.h: FDependencyRef, ULinker::DependsMap |
| import path construction | Core/Src/UnLinker.cpp: ULinker::GetImportPathName |
| verification entry | Core/Src/UnLinker.cpp: ULinkerLoad::Verify |
| import verification | Core/Src/UnLinker.cpp: ULinkerLoad::VerifyImport, VerifyImportInner |
| export matching | Core/Src/UnLinker.cpp: ULinkerLoad::FindExportIndex |
| dependency-map loading | Core/Src/UnLinker.cpp: ULinkerLoad::SerializeDependsMap |
| recursive dependency traversal | Core/Src/UnLinker.cpp: GatherExportDependencies, GatherImportDependencies |

## Serialized import identity

FObjectImport serializes ClassPackage, ClassName, OuterIndex, then ObjectName. On load SourceLinker is reset to NULL, SourceIndex to INDEX_NONE, and XObject to NULL. Those three fields are runtime resolution state, not stored dependency metadata.

UnrealDB must preserve ClassPackage, ClassName, ObjectName, and the complete OuterIndex chain.

## Package-index semantics

UE3 PACKAGE_INDEX is an INT. Values greater than zero address ExportMap at index value-1; values less than zero address ImportMap at index -value-1; zero is ROOTPACKAGE_INDEX when used as OuterIndex. These are not UE1/UE2 compact indices.

## Import path and outer traversal

ULinker::GetImportPathName begins at package index -ImportIndex-1 and walks OuterIndex until ROOTPACKAGE_INDEX. Ordinary resources come from ImportMap. Cooked data can have an import outer that is an export, and the source handles that distinction while constructing paths.

The path is built from the complete outer chain. Normal components use a dot separator; the source uses subobject notation for the package-to-nonpackage boundary according to its class tests. A leaf ObjectName alone is not sufficient import identity.

## Verification gates

ULinkerLoad::Verify does not run import verification unconditionally. It considers PKG_Cooked, game/editor/UCC state, cooker state, bHaveImportsBeenVerified, PKG_RequireImportsAlreadyLoaded, and script-patcher configuration. When verification is enabled it iterates Summary.ImportCount and calls VerifyImport for every import.

These are runtime loader gates. They must not be converted into claims that serialized imports cease to exist in cooked packages.

## Top-level package imports

In VerifyImportInner, OuterIndex == ROOTPACKAGE_INDEX identifies a package import. The source requires ClassName == Package and ClassPackage == Core. It creates/finds the package named by ObjectName and calls GetPackageLinker to obtain the provider linker.

Therefore the root ObjectName in the serialized outer chain identifies the provider package. UnrealDB must not substitute an engine-version lookup table or infer the provider from ClassPackage.

## Recursive outer-first resolution

For ordinary non-root uncooked imports, the outer must itself be an import. VerifyImportInner recursively verifies ImportMap[-OuterIndex-1], then copies that outer import's SourceLinker to the child.

The effective order is provider package, outer objects, then nested object. This is why a same-named leaf object in the wrong group is not a match.

For cooked packages, source explicitly recognizes that an import outer can be an export. In the examined branch VerifyImportInner returns rather than applying the ordinary algorithm and contains an Epic TODO about possibly locating the original package linker. UnrealDB must not invent the missing behavior.

## Pre-hash import and class fixups

UE3 does not hash the raw serialized ImportMap immediately. After serializing imports and exports, ULinkerLoad runs FixupImportMap(), then RemapClasses(), and only later CreateExportHash(). VerifyImport therefore sees the post-fixup identities.

FixupImportMap contains fixed engine compatibility rules that are independent of game INI ClassRemap configuration. In the audited source it remaps Engine.SoundCueLocalized class identity to SoundCue, remaps references whose ClassName is SoundCueLocalized and ClassPackage is Engine to SoundCue, changes the old SequenceObjects package import to Engine, and changes ClassPackage SequenceObjects to Engine. UnrealDB must apply these UE3-only fixed rules before building VerifyImport identity buckets on both consumer and provider sides.

RemapClasses also has a version-gated compatibility fix for packages older than VER_FIXED_PREFAB_SEQUENCES (536). When the package contains the affected prefab imports, Sequence exports used for Prefabs are reclassified to Engine.PrefabSequenceContainer or Engine.PrefabSequence before export hashing. UnrealDB's UE3 export identity projection must use that effective class identity for pre-536 packages.

These source-defined fixes are not permission to consume UnrealDB's configurable class-remap table for UE3. Runtime/config remaps that require external INI state remain outside deterministic package-byte resolution unless separately proven and supplied.

## Exact provider-export match

Once SourceLinker exists, UE3 hashes candidates from ObjectName, ClassName, and ClassPackage and walks that source linker's export hash chain.

A candidate initially matches only when all three are equal: export ObjectName equals import ObjectName, export class name equals import ClassName, and export class package equals import ClassPackage.

HashNames is a bucket selector, not a relaxation of identity. If an export in that bucket fails the exact tuple or resolved outer qualification, UE3 continues to the next export in the same hash chain. A tuple match with the wrong outer is therefore not a successful import. Once an otherwise matching candidate reaches the private-export failure path, ordinary runtime loading does not continue past it as though it were merely an outer mismatch.

ClassPackage identifies the package containing the object's class. It is not necessarily the package containing the imported object.

The derived full or relative object path is not part of this candidate lookup. It is useful catalog/display identity, but UnrealDB must not use a path hash as a prerequisite for UE3 VerifyImport matching; doing so can reject an export before Epic's serialized ObjectName/ClassName/ClassPackage and OuterIndex checks run.

## Per-import resolution on one source linker

ULinkerLoad::Verify iterates every ImportMap entry and calls VerifyImport independently. Once a top-level package import establishes SourceLinker, each descendant import independently succeeds or fails by receiving its own SourceIndex. One failed object import does not erase successful sibling imports from the same SourceLinker.

The source operates on one concrete provider linker chosen by the package-loading environment before per-Import verification. UnrealDB must never combine exports from different files or choose among duplicate catalogue providers by import coverage. If the original provider-selection environment is unavailable and several physical candidates remain, the static result is unresolved provider-environment ambiguity. The named UDKUltimate Core source is present at `L:\Source\Engine\UE3\Unreal Engine [v3.0] UDKUltimate [05-11-17]`, and a later full UE3 build-10897/changelist-1532151 Core tree is present at `L:\Source\Engine\UE3\Unreal Engine 3 (10897)`; the audited 10897 linker/version files match `CodeRedModding/UnrealEngine3` byte-for-byte. Game-specific policy still requires its own source audit.

## Outer qualification

The candidate must also have the correct outer. If the resolved outer import has no SourceIndex, the candidate must have SourceExport.OuterIndex == ROOTPACKAGE_INDEX. Otherwise OuterImport.SourceIndex + 1 must equal SourceExport.OuterIndex.

UnrealDB must therefore reject a same-name/class candidate found under a different outer.

## Public visibility

VerifyImportInner checks RF_Public on a matching source export. The engine has editor/error-recovery handling for private imports, including checks for references through export super, class, outer and archetype indices and import outers. Ordinary external provider matching must not treat a private same-name export as equivalent to a public provider export.

## Successful resolution

On success, Import.SourceIndex is set to the matching source ExportMap index while SourceLinker identifies the provider linker. These fields are transient, but the pair is the logical result UnrealDB should reproduce when verifying a provider.

## Native/transient runtime fallback

If no source export is found, VerifyImportInner can search already-loaded runtime objects by ClassPackage, ClassName, outer and ObjectName. It contains special acceptance for public/native/transient objects and corresponding class-default objects, and LOAD_FindIfFail can affect this path.

This depends on runtime object state. UnrealDB must not manufacture it from catalog metadata and call that source-equivalent dependency resolution.

## UObjectRedirector fallback

VerifyImport wraps VerifyImportInner. If the provider package was found but the requested non-root object was not, and the requested object is not already ObjectRedirector, UE3 retries using ClassName ObjectRedirector and ClassPackage Core.

When found, the redirector is created and preloaded to obtain DestinationObject. The destination must exist and normally have the original requested class; the source permits the class difference for a class-default object. The source also diagnoses a redirector destination that is another redirector in the failed type-check path as likely circular redirection.

On success the original serialized ClassName and ClassPackage are restored while runtime SourceIndex and SourceLinker are updated from the destination object.

This fallback is proven for this UE3 revision only. It must not be copied into another engine/game specification without that source proving it.

## Fully qualified matching and disabled non-qualified fallback

ULinkerLoad::Create requires an outer and performs FindExportIndex using that outer. The source contains a diagnostic non-qualified lookup block, but FIND_OBJECT_NONQUALIFIED is defined as 0. Debug code can report what such a lookup would have found; it does not make that object the result.

UnrealDB must not add a same-name-anywhere-in-package fallback.

FindExportIndex itself first performs exact object-name, outer, class-package and class-name matching. Its general helper can subsequently accept an export whose runtime class derives from the requested class. That helper behavior must not be confused with VerifyImportInner's direct provider-export loop, which performs the explicit class identity comparisons above.

## Serialized DependsMap

UE3 separately stores TArray<TArray<INT>> DependsMap, one dependency array per export. Summary.DependsOffset points to it. SerializeDependsMap sizes the outer array to Summary.ExportCount and serializes one TArray<INT> for each export.

Each dependency entry is a UE3 signed package index and can therefore identify either an import or an export.

SerializeDependsMap skips loading this map when GUseSeekFreeLoading is true or execution is neither editor nor commandlet. This is runtime loading behavior, not a different on-disk index format.

## Recursive export dependencies

GatherExportDependencies returns immediately if DependsMap is empty and otherwise requires DependsMap.Num() == ExportMap.Num(). For each package index in DependsMap[ExportIndex], an import calls GatherImportDependencies(-index-1); an export converts to export index index-1, adds the linker/export pair to a set, and recursively gathers that export's dependencies if it was not already present.

The set prevents repeated recursion through an already visited resolved export.

## Recursive import dependencies

GatherImportDependencies ignores top-level package imports as object dependencies when OuterIndex == ROOTPACKAGE_INDEX. It also returns when XObject already exists.

If SourceLinker/SourceIndex is unresolved, it invokes VerifyImportInner while bIsGatheringDependencies is true. Provider loading then uses LOAD_NoVerify so resolving one dependency does not force verification of every import in that provider package.

A resolved import is converted to an FDependencyRef identifying a provider linker and export index, added to the dependency set, and recursively expanded through that export's DependsMap.

## Two dependency concepts

UE3 therefore has two distinct dependency structures that UnrealDB must not flatten:

1. Import requirements: FObjectImport records describing objects referenced outside the package.
2. Per-export dependency edges: DependsMap records describing objects required by individual exports.

Package-level Requires reporting can derive external requirements from imports. Exact export dependency analysis should use DependsMap where present and recurse through resolved imports as the source does.

## Required package and object identity

The provider package comes from the top-level resource reached by walking the import's OuterIndex chain. The required object is structurally identified by provider package, full outer chain, ObjectName, ClassName, and ClassPackage.

A provider package merely existing is not proof that an import is satisfied. The source-defined export identity, outer relationship and visibility checks still apply.

## Runtime-only behavior

The following influence engine loading but are not derivable solely from the serialized import record: game/editor/UCC state, cooker state, LOAD_FindIfFail, LOAD_NoVerify, LOAD_RemappedPackage, already-loaded native/transient objects, package compiling state, editor private-object recovery, script-patcher state, multilanguage/seek-free remapping, and runtime availability of other packages.

UnrealDB must keep these separate from deterministic package-byte rules.

## UnrealDB conformance requirements

1. Decode UE3 package indices as signed INT values, never compact indices.
2. Preserve ClassPackage, ClassName, ObjectName and the complete outer chain.
3. Identify provider package by walking to the root package import.
4. Do not use engine-version tables as a substitute for serialized provider identity.
5. Match provider exports by ObjectName, ClassName and ClassPackage exactly as VerifyImportInner does.
6. Enforce the resolved outer relationship.
7. Treat ordinary externally imported source exports as public.
8. Keep FObjectImport requirements separate from DependsMap edges.
9. Decode DependsMap entries with the same signed package-index rules.
10. Do not implement a non-qualified same-name fallback.
11. Follow UObjectRedirector only when its serialized destination can actually be resolved.
12. Do not emulate runtime native/transient lookup from insufficient catalog data.
13. Preserve cooked import/export outer relationships: use the mixed import/export graph for `GetImportPathName`-style package/path identity, while keeping `VerifyImportInner` outcome unresolved where the audited source returns at its cooked import-to-export TODO.
14. Keep runtime/configuration mechanisms explicitly separate.
15. Never borrow a fallback from another engine or game because its version number appears similar.

## Source-reference matrix

| Rule | Proving source |
|---|---|
| signed import/export indices and root | Core/Inc/UnLinker.h |
| serialized import identity and transient reset | Core/Src/UnLinker.cpp: FObjectImport serializer |
| import path/outer traversal | ULinker::GetImportPathName |
| verification runtime gates | ULinkerLoad::Verify |
| root Core.Package requirement/provider linker | ULinkerLoad::VerifyImportInner |
| recursive outer-first resolution | ULinkerLoad::VerifyImportInner |
| exact object/class/class-package match | ULinkerLoad::VerifyImportInner |
| resolved outer qualification | ULinkerLoad::VerifyImportInner |
| RF_Public check | ULinkerLoad::VerifyImportInner |
| native/transient runtime fallback | ULinkerLoad::VerifyImportInner |
| redirector retry/destination validation | ULinkerLoad::VerifyImport |
| fully qualified Create lookup | ULinkerLoad::Create |
| non-qualified fallback disabled | ULinkerLoad::Create |
| general exact/subclass helper | ULinkerLoad::FindExportIndex |
| one DependsMap entry array per export | ULinkerLoad::SerializeDependsMap |
| recursive export dependency traversal | ULinkerLoad::GatherExportDependencies |
| import resolution during dependency traversal | ULinkerLoad::GatherImportDependencies |
| dependency identity is linker plus export index | Core/Inc/UnLinker.h: FDependencyRef |

## Revision boundary

This document describes only the UDKUltimate UE3 tree named above. Earlier UE3 snapshots and UT3 must be checked against their own authoritative source before UnrealDB treats their dependency behavior as equivalent.