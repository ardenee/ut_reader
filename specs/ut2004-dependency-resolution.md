# Unreal Tournament 2004 Dependency and Import Resolution

## Scope

This specification documents dependency discovery and import resolution from the supplied UT2004 source only.

- Repository: `ardenee/UT2004src`
- Branch: `main`
- Engine build: 3369
- Package version: 128
- Licensee version: 0x1D

Primary authority is `Core/Src/UnLinker.cpp`, with `Core/Src/UnObj.cpp` used for package linker lookup and surrounding object-load behavior.

No behavior is inherited from UT2003, UE2.5, Unreal II, UT99, or another engine/game.

## Authoritative references

| Behavior | Source |
|---|---|
| export hash | `Core/Src/UnLinker.cpp: HashNames` |
| Verify / VerifyImport | `Core/Src/UnLinker.cpp` |
| class identity | `GetExportClassPackage`, `GetExportClassName` |
| full import/export names | `ULinker::GetImportFullName`, `GetExportFullName` |
| FindExportIndex | `Core/Src/UnLinker.cpp` |
| CreateImport | `Core/Src/UnLinker.cpp` |
| IndexToObject | `Core/Src/UnLinker.cpp` |
| package linker lookup | `Core/Src/UnObj.cpp: UObject::GetPackageLinker` |
| package creation | `Core/Src/UnObj.cpp: UObject::CreatePackage` |
| object loading/remap block | `Core/Src/UnObj.cpp: UObject::StaticLoadObject` |

## Serialized dependency identity

Each import carries:

- ClassPackage
- ClassName
- PackageIndex
- ObjectName

PackageIndex supplies the import parent chain. These serialized values are the primary dependency evidence.

Runtime fields SourceLinker, SourceIndex and XObject are not serialized.

## Import parent semantics

A top-level import has `PackageIndex == 0`.

A nested import uses a negative PackageIndex pointing to another import:

`parent import index = -PackageIndex - 1`

GetImportFullName walks this negative chain until zero.

For catalogue dependency extraction, the top-level ObjectName reached through this chain identifies the declared external package.

## Verify phase

Unless `LOAD_NoVerify` is set, ULinkerLoad construction calls Verify.

Verify:

1. clears PKG_BrokenLinks on LinkerRoot when it is a package;
2. iterates all imports;
3. calls VerifyImport for each;
4. removes the linker from GObjLoaders and rethrows on a verification exception;
5. marks Verified.

## VerifyImport early return

VerifyImport returns immediately when:

- SourceLinker is already set and SourceIndex is not INDEX_NONE; or
- ClassPackage is NAME_None; or
- ClassName is NAME_None; or
- ObjectName is NAME_None.

Do not simplify the first test to SourceIndex alone.

## Top-level package import

For `Import.PackageIndex == 0`, source requires:

- ClassName == Package
- ClassPackage == Core

It creates/finds a runtime package named by Import.ObjectName and calls:

`GetPackageLinker(TmpPkg, NULL, LOAD_Throw | (LoadFlags & LOAD_Propagate), NULL, NULL)`

There is no alternate provider name or alias retry in this VerifyImport path.

## Package linker lookup

With no explicit filename, GetPackageLinker resolves a file from the package name using `appFindPackageFile`.

The wider UT2004 runtime can use configured paths, cache locations, language variants and GUID checks. Those are runtime filesystem/configuration rules and are not serialized dependency metadata.

UnrealDB should resolve package names against its catalogue while preserving the declared package identity.

## Nested import setup

For a nested import:

1. PackageIndex must be negative;
2. VerifyImport recursively verifies the parent;
3. child SourceLinker is copied from parent SourceLinker;
4. if SourceLinker exists, source walks the negative parent chain to its top import;
5. it counts Depth while walking;
6. it creates/finds runtime Pkg from the top import ObjectName.

The old assertion requiring SourceLinker is commented out.

Depth is not subsequently used by active VerifyImport logic.

## Export hash

HashNames is exactly:

`A.GetIndex() + 7 * B.GetIndex() + 31 * C.GetIndex()`

The result is masked by `ARRAY_COUNT(ExportHash)-1`; ExportHash contains 256 buckets.

The loader hashes each export using:

- ObjectName
- GetExportClassName
- GetExportClassPackage

No name normalization or package aliasing is performed by HashNames.

## Provider candidate matching

VerifyImport hashes:

- Import.ObjectName
- Import.ClassName
- Import.ClassPackage

A candidate provider export must exactly match:

- Source.ObjectName == Import.ObjectName
- provider GetExportClassName(j) == Import.ClassName
- provider GetExportClassPackage(j) == Import.ClassPackage

No generic fuzzy match, alias table, or case-derived provider substitution appears here.

## Export class identity

GetExportClassName:

- ClassIndex < 0 -> referenced import ObjectName
- ClassIndex > 0 -> referenced export ObjectName
- zero -> Class

GetExportClassPackage:

- ClassIndex < 0 -> parent import ObjectName of the class import
- ClassIndex > 0 -> LinkerRoot package name
- zero -> Core

These functions define class identity used by import matching and hashing.

## Parent/outer matching

For nested imports, after object/class matching:

If ParentImport.SourceLinker exists:

- when ParentImport.SourceIndex == INDEX_NONE, candidate Source.PackageIndex must be zero;
- when ParentImport.SourceIndex is known and `ParentImport.SourceIndex + 1 != Source.PackageIndex`, candidate is nevertheless accepted when Source.PackageIndex is zero.

Therefore UT2004 has an explicit provider-root fallback when the expected resolved parent does not match.

Do not replace this with strict outer equality.

## RF_Public requirement

A matching provider export must have RF_Public.

If not public:

### LOAD_Forgiving

- mark LinkerRoot PKG_BrokenLinks;
- log broken import;
- return.

### Otherwise

Throw `FailedImportPrivate`.

The private export is not accepted.

## Mesh -> LodMesh compatibility retry

After provider search, source unconditionally checks:

`appStricmp(*Import.ClassName, TEXT("Mesh")) == 0`

If true:

- Import.ClassName becomes LodMesh;
- control jumps to `Rehack`;
- hash/provider lookup runs again.

This check is **not guarded by SourceIndex == INDEX_NONE**.

Therefore a source-faithful implementation must not silently rewrite it into an only-if-Mesh-was-missing fallback.

The mutation also changes the runtime Import.ClassName.

## Runtime public/native/transient resolution

If no SourceIndex was found and Pkg exists, VerifyImport attempts runtime resolution:

1. find package named ClassPackage;
2. find UClass named ClassName in it;
3. StaticFindObject of that class under Pkg using ObjectName;
4. accept it only if RF_Public, RF_Native and RF_Transient are all set;
5. assign XObject and increment GImportCount.

This is runtime object state. It is not a byte-level alternative package dependency.

## SafeReplace behavior

When FindClass exists but no acceptable public/native/transient object is found:

- editor load-error reporting may occur;
- source logs Missing;
- `SafeReplace = 1`.

There is no `CLASS_SafeReplace` test in this path.

SafeReplace suppresses the final unresolved-import failure.

For UnrealDB dependency extraction, this runtime tolerance must not erase the serialized declared dependency.

## Final unresolved failure

The final failure block exists only when:

`Import.SourceIndex == INDEX_NONE && Pkg != NULL`

If neither XObject nor SafeReplace exists:

- log Failed import;
- with LOAD_Forgiving, set PKG_BrokenLinks, log Broken import, and return;
- otherwise throw FailedImport.

Do not generalize this into an unconditional rule for every unresolved import.

## FindExportIndex

This function is separate from VerifyImport.

### Exact search

It hashes ObjectName/ClassName/ClassPackage and searches matching bucket entries.

Required match:

- ObjectName
- PackageIndex, unless caller passed INDEX_NONE wildcard
- GetExportClassPackage
- GetExportClassName

### Subclass search

If exact class lookup fails, it scans exports with matching ObjectName and package constraint.

For each, it resolves the export's runtime class via IndexToObject and walks GetSuperClass.

If any class in that chain has FName equal to requested ClassName, the export is returned.

This is runtime class-hierarchy behavior and belongs to FindExportIndex, not VerifyImport's serialized provider matching.

### Mesh compatibility

If still not found and requested ClassName is Mesh:

- change requested class to LodMesh;
- retry from Rehack.

Otherwise return INDEX_NONE.

## CreateImport

If Import.XObject is null:

1. if SourceLinker is null:
   - BeginLoad
   - VerifyImport(Index)
   - EndLoad
2. if SourceIndex is not INDEX_NONE:
   - call SourceLinker->CreateExport(SourceIndex)
   - store result in XObject
   - increment GImportCount

Return XObject.

CreateImport therefore materializes an already resolved provider export; it does not independently perform fuzzy dependency discovery.

## Signed object references

IndexToObject:

- positive -> local export at Index-1 via CreateExport
- negative -> import at -Index-1 via CreateImport
- zero -> null

Both nonzero paths bounds-check their corresponding maps.

## StaticLoadObject package remap code is disabled

UT2004 declares `GObjPackageRemap`, but the remapped-package retry inside `UObject::StaticLoadObject` is enclosed in a block comment.

The disabled code would, after load failure:

- check InOuter;
- require LOAD_NoRemap not set;
- require no explicit Filename;
- MultiFind remap names for InOuter's FName;
- recursively retry StaticLoadObject with LOAD_NoRemap.

Because the entire block is commented out, it is **not active behavior in this supplied UT2004 source**.

UnrealDB must not implement it as a UT2004 dependency fallback.

This differs from treating a runtime remap mechanism as active merely because the global data structure exists.

## ClassRemap

No active ClassRemap behavior appears in the reviewed UT2004 VerifyImport path.

Do not add one.

In particular, UnrealDB cannot infer arbitrary configuration remaps from package bytes.

## PackageRemap

No active PackageRemap behavior appears in VerifyImport.

The only remap retry found in the reviewed object-loading source is the commented-out StaticLoadObject block described above.

Therefore there is no source basis for an active package-remap dependency fallback here.

## UnrealI / UnrealShare

The reviewed UT2004 resolver does not contain the UT99-specific:

- UnrealI -> UnrealShare provider retry;
- UnrealShare -> UnrealI hash normalization;
- UnrealI/UnrealShare class-package equivalence;
- synthetic UnrealShare reparenting.

Do not inherit those rules.

## Dependency extraction for UnrealDB

For each serialized import, UnrealDB should preserve:

1. ClassPackage
2. ClassName
3. ObjectName
4. PackageIndex
5. full import parent chain
6. top-level declared package
7. whether object-level provider resolution succeeds, when known

A useful distinction is:

- **declared dependency**: serialized top-level package reached through import ancestry;
- **object requirement**: nested import identity and class identity;
- **resolved provider**: catalogue package/export satisfying the source-compatible matching rules;
- **runtime satisfaction**: native/transient object behavior which UnrealDB normally cannot reproduce from file bytes alone.

Do not remove a declared dependency because runtime-native satisfaction might have existed in a running UT2004 process.

## Active compatibility/fallback inventory

Source-proven active behavior:

1. exact object/class-package/class-name provider matching;
2. provider-root acceptance when nested parent resolution does not match;
3. RF_Public enforcement;
4. Mesh -> LodMesh retry in VerifyImport;
5. runtime public/native/transient object binding;
6. broad SafeReplace once runtime class exists;
7. LOAD_Forgiving broken-link handling;
8. subclass matching in FindExportIndex;
9. Mesh -> LodMesh retry in FindExportIndex.

Not active / not present in this resolver:

- ClassRemap;
- active PackageRemap;
- UnrealI -> UnrealShare retry;
- UnrealShare -> UnrealI hash normalization;
- UnrealI/UnrealShare class-package equivalence;
- CLASS_SafeReplace;
- arbitrary aliases;
- fuzzy package substitution.

## Runtime/config-only unavailable behavior

The package file does not serialize:

- configured package search paths;
- cache directory/ext;
- current language;
- runtime-loaded classes/objects;
- native/transient object inventory;
- editor/client/server process state;
- arbitrary external remap configuration.

UnrealDB should not invent these from package bytes.

## Conformance requirements

1. derive package dependencies from actual import ancestry;
2. preserve all import identity fields;
3. use exact UT2004 class identity rules;
4. use exact HashNames if reproducing resolver hashing;
5. preserve provider-root outer fallback;
6. require RF_Public for provider export resolution;
7. preserve exact Mesh -> LodMesh flow if emulating runtime verification;
8. keep runtime-native satisfaction distinct from serialized dependency extraction;
9. do not require CLASS_SafeReplace;
10. preserve forgiving/non-forgiving behavior only where applicable;
11. keep FindExportIndex subclass matching separate from VerifyImport;
12. do not activate commented package-remap code;
13. do not implement ClassRemap;
14. do not inherit UT99 aliases/fallbacks;
15. do not infer configuration-dependent remaps.

## Source-reference matrix

| Rule | Source |
|---|---|
| Verify lifecycle | `Core/Src/UnLinker.cpp: ULinkerLoad::Verify` |
| import resolution | `ULinkerLoad::VerifyImport` |
| hash | `HashNames` |
| class identity | `GetExportClassName`, `GetExportClassPackage` |
| import path | `ULinker::GetImportFullName` |
| exact/subclass lookup | `ULinkerLoad::FindExportIndex` |
| import materialization | `ULinkerLoad::CreateImport` |
| signed reference resolution | `ULinkerLoad::IndexToObject` |
| package lookup | `Core/Src/UnObj.cpp: UObject::GetPackageLinker` |
| package creation | `UObject::CreatePackage` |
| disabled package remap | `UObject::StaticLoadObject` |

## Next specification

Proceed to the next source-defined target in `specs/README.md`; do not infer its behavior from UT2004.
