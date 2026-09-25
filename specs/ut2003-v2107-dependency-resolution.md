# Unreal Tournament 2003 v2107 Dependency and Import Resolution

## Scope

This specification documents dependency and import resolution from the supplied UT2003 source only:

- Repository: `ardenee/Unreal_Tournament_2003_v2107`
- Branch: `main`
- package format companion: `ut2003-v2107-package-format.md`

No UE2.5, Unreal II, UT2004, or UT99 behavior is imported into this specification.

## Authoritative source references

| Behavior | Source |
|---|---|
| import verification | `Core/Src/UnLinker.cpp: ULinkerLoad::VerifyImport` |
| verification pass | `ULinkerLoad::Verify` |
| hash | `HashNames` |
| class identity | `GetExportClassPackage`, `GetExportClassName` |
| explicit lookup | `FindExportIndex` |
| import realization | `CreateImport` |
| signed references | `IndexToObject` |
| package loading | `Core/Src/UnObj.cpp: UObject::GetPackageLinker` |
| package creation | `UObject::CreatePackage` |
| general object-load package remap | `UObject::StaticLoadObject` |

## Declared package dependencies

Serialized imports establish external references.

A root import has `PackageIndex == 0`. Nested imports point to parent imports with negative PackageIndex values.

For dependency extraction, UnrealDB should follow each nested import to its root import and preserve the full class/object/outer chain.

## Verification pass

Unless `LOAD_NoVerify` is active, linker construction calls `Verify()`.

Verify clears `PKG_BrokenLinks`, iterates `Summary.ImportCount`, calls `VerifyImport`, removes the linker from `GObjLoaders` on a thrown verification error, and rethrows.

## VerifyImport early return

Verification returns immediately when:

- SourceLinker exists and SourceIndex is not INDEX_NONE; or
- ClassPackage is NAME_None; or
- ClassName is NAME_None; or
- ObjectName is NAME_None.

SourceIndex by itself is not the already-verified test.

## Root provider import

When `PackageIndex == 0`, source requires:

- ClassName == Package
- ClassPackage == Core

It creates/finds a package named by ObjectName and calls:

`GetPackageLinker(TmpPkg, NULL, LOAD_Throw | (LoadFlags & LOAD_Propagate), NULL, NULL)`

A caught error is rethrown.

There is no alternate-provider fallback in this VerifyImport branch.

## GetPackageLinker provider lookup

With no explicit filename, `GetPackageLinker` resolves the requested package name through `appFindPackageFile`.

It may reuse a linker already loaded for the same LinkerRoot.

VerifyImport supplies no CompatibleGuid.

Filesystem paths and search configuration are runtime state. UnrealDB should resolve the declared root package against its catalogue without inventing filesystem behavior.

## Nested imports

For nonzero PackageIndex, source requires a negative value.

It:

1. recursively verifies the parent import;
2. inherits the parent's SourceLinker;
3. leaves the SourceLinker assertion commented out;
4. if a SourceLinker exists, walks negative parents to the top;
5. counts Depth;
6. creates/finds runtime Pkg using the top import's ObjectName.

Depth is not subsequently used by this VerifyImport implementation.

## Hash and exact candidate identity

Hash formula:

`A.GetIndex() + 7 * B.GetIndex() + 31 * C.GetIndex()`

The export hash has 256 buckets.

VerifyImport hashes ObjectName, ClassName, and ClassPackage.

A candidate matches only when all three are exact:

- Source.ObjectName == Import.ObjectName
- provider GetExportClassName(j) == Import.ClassName
- provider GetExportClassPackage(j) == Import.ClassPackage

No UnrealI/UnrealShare normalization or class-package alias exists here.

## Class identity

GetExportClassName:

- negative ClassIndex -> referenced import ObjectName
- positive -> referenced export ObjectName
- zero -> Class

GetExportClassPackage:

- negative -> class import's parent import ObjectName
- positive -> LinkerRoot package name
- zero -> Core

## Parent/outer candidate rule

For a nested import whose parent has a SourceLinker:

- if parent SourceIndex is INDEX_NONE, candidate must have Source.PackageIndex == 0;
- if parent SourceIndex exists but `Parent.SourceIndex + 1 != Source.PackageIndex`, candidate is still accepted when Source.PackageIndex == 0.

Thus a provider-root export is an explicit fallback for parent mismatch.

It is not a generic fuzzy outer match.

## RF_Public

A matching export without RF_Public:

- under LOAD_Forgiving: marks PKG_BrokenLinks, logs broken import, returns;
- otherwise: throws FailedImportPrivate.

A public candidate sets Import.SourceIndex.

## Mesh -> LodMesh

After provider search, source performs:

- case-insensitive test for ClassName `Mesh`;
- replaces it with `LodMesh`;
- jumps back and repeats provider lookup.

This occurs regardless of whether the first Mesh pass assigned SourceIndex.

It is an explicit hardcoded compatibility path, not permission for generic remapping.

## Runtime public/native/transient satisfaction

If SourceIndex remains INDEX_NONE and Pkg exists, source:

1. finds runtime package ClassPackage;
2. finds runtime class ClassName;
3. finds ObjectName under Pkg;
4. accepts the runtime object only if it has RF_Public, RF_Native, and RF_Transient.

On success, XObject is assigned and GImportCount incremented.

This depends on runtime state and cannot be reconstructed from package bytes alone.

## SafeReplace

If the runtime class exists but no acceptable runtime object is found, source:

- optionally records an editor load error;
- logs the missing resource;
- sets local SafeReplace = 1.

There is no CLASS_SafeReplace test.

The later fatal unresolved-import path is suppressed when SafeReplace is set.

## Final unresolved failure

Inside the `SourceIndex == INDEX_NONE && Pkg != NULL` block, when XObject is null and SafeReplace is false:

- logs failed import;
- LOAD_Forgiving marks PKG_BrokenLinks, logs broken import, and returns;
- otherwise throws FailedImport.

This must not be generalized into an unconditional failure for every unresolved serialized import.

## FindExportIndex

This function is distinct from VerifyImport.

First it performs exact hashed lookup using:

- ObjectName
- PackageIndex, unless requested PackageIndex is INDEX_NONE
- ClassPackage
- ClassName

If not found, it scans matching object/package exports, resolves each runtime export class, and walks the superclass chain. A class whose FName equals requested ClassName is accepted.

If still not found and requested ClassName is Mesh, it retries as LodMesh.

Subclass matching therefore belongs to FindExportIndex, not VerifyImport.

## CreateImport

When XObject is absent:

1. if SourceLinker is absent, BeginLoad;
2. VerifyImport;
3. EndLoad;
4. if SourceIndex exists, call SourceLinker->CreateExport(SourceIndex);
5. assign XObject;
6. increment GImportCount.

Verification and provider object creation are separate phases.

## Signed object resolution

IndexToObject:

- positive -> local export Index-1
- negative -> import -Index-1
- zero -> null

Both table paths are bounds-checked before creation.

## Important package-remap boundary

The reviewed `ULinkerLoad::VerifyImport` contains **no ClassRemap or PackageRemap mechanism**.

However, UT2003 does contain a separate runtime package-remap mechanism in `UObject::StaticLoadObject`.

After a StaticLoadObject failure, when:

- InOuter exists;
- LOAD_NoRemap is not set;
- no explicit Filename was supplied;

the engine queries:

`GObjPackageRemap->MultiFind(InOuter->GetFName(), Remaps)`

and recursively retries loading from each replacement package with LOAD_NoRemap.

This is **not part of serialized import verification** and is not evidence that package bytes contain remap information.

The remap table is runtime/config state. UnrealDB cannot infer arbitrary remaps from a package file and must not use this mechanism to alter declared dependency identity.

This distinction is important: saying “UT2003 has no PackageRemap” globally would be incorrect; saying “VerifyImport has no package-remap path, while StaticLoadObject has a runtime remap table” matches the supplied source.

## ClassRemap

No ClassRemap symbol/path was found in the reviewed UT2003 linker implementation.

No generic class remap should be added to dependency verification.

## UnrealI / UnrealShare and CLASS_SafeReplace

The reviewed UT2003 `UnLinker.cpp` contains no:

- UnrealI
- UnrealShare
- CLASS_SafeReplace

logic.

Do not inherit those rules from UT99 or another engine/game.

## Declared dependency versus runtime resolution

UnrealDB should distinguish:

- **declared dependency**: serialized root package import;
- **declared object reference**: serialized nested import chain;
- **catalogue provider candidate**: file associated with the root package identity;
- **source-level object resolution**: exact provider export/class/outer/public checks;
- **runtime satisfaction**: native/transient objects and package remap state.

Failure to reproduce runtime state must not erase a dependency proven by the package bytes.

## Active compatibility inventory

VerifyImport / linker behavior proves:

1. exact object/class/package matching;
2. provider-root outer fallback;
3. RF_Public requirement;
4. Mesh -> LodMesh;
5. runtime public/native/transient object binding;
6. broad local SafeReplace when runtime class exists;
7. LOAD_Forgiving broken-link behavior;
8. FindExportIndex subclass matching;
9. FindExportIndex Mesh -> LodMesh.

Separately, StaticLoadObject proves a runtime GObjPackageRemap retry path after object-load failure.

Not proven in VerifyImport:

- ClassRemap;
- PackageRemap inside import verification;
- UnrealI/UnrealShare compatibility;
- CLASS_SafeReplace gating;
- arbitrary class aliases;
- fuzzy provider matching.

## UnrealDB conformance requirements

For this UT2003 source:

1. derive declared dependencies from serialized import chains;
2. preserve root package and nested object identities;
3. keep declared dependency separate from runtime resolvability;
4. use exact object/class/package identity for provider export matching;
5. preserve the explicit provider-root outer fallback;
6. enforce RF_Public if emulating VerifyImport;
7. implement only source-proven Mesh -> LodMesh compatibility;
8. do not implement generic ClassRemap;
9. do not apply runtime GObjPackageRemap to serialized dependency extraction;
10. do not infer remap-table entries from package bytes;
11. do not implement UnrealI/UnrealShare aliases;
12. do not model SafeReplace as CLASS_SafeReplace;
13. keep FindExportIndex subclass matching separate from VerifyImport;
14. represent forgiving broken links separately from successful resolution;
15. keep all behavior scoped to this UT2003 source.

## Source-reference matrix

| Rule | Source symbol |
|---|---|
| verification pass | `ULinkerLoad::Verify` |
| root/nested import logic | `ULinkerLoad::VerifyImport` |
| hash | `HashNames` |
| exact provider matching | `VerifyImport` |
| outer fallback | `VerifyImport` |
| RF_Public | `VerifyImport` |
| Mesh -> LodMesh | `VerifyImport`, `FindExportIndex` |
| runtime native/transient | `VerifyImport` |
| SafeReplace | `VerifyImport` |
| forgiving behavior | `VerifyImport` |
| class identity | `GetExportClassName`, `GetExportClassPackage` |
| subclass lookup | `FindExportIndex` |
| import realization | `CreateImport` |
| signed object resolution | `IndexToObject` |
| provider filesystem lookup | `UObject::GetPackageLinker` |
| runtime package remap | `UObject::StaticLoadObject` |

## Next specification

The next target should be **UT2004 package format and reading**, using only `ardenee/UT2004src` as authority.
