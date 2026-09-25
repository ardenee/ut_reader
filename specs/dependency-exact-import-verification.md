# Exact Import Verification Rules by Engine Revision

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

Import verification is not merely "does an export with this name exist". It is revision-specific linker behavior.

## UT99 retail v1.400

The retail `ULinkerLoad::VerifyImport` proves this sequence:

1. skip an already resolved import or one whose class package/class/object name is `None`;
2. if `PackageIndex == 0`, require the import to be `Core.Package` and load/create the package named by `ObjectName`;
3. otherwise require a negative parent import index, recursively verify that parent, and inherit its `SourceLinker`;
4. search the provider's export hash by `ObjectName + ClassName + ClassPackage`;
5. require exact object name, class name and class package, subject only to explicit source fallbacks;
6. enforce the expected parent/source export relationship when the import has an outer;
7. require the matched source export to be `RF_Public`;
8. record `SourceIndex` when matched;
9. only then consider source-defined native/transient/safe-replace and historical fallbacks.

UT99 retail contains explicit historical exceptions: `UnrealShare -> UnrealI` hashing/package compatibility, `Mesh -> LodMesh`, and an UnrealI/UnrealShare shareware package fallback. These are UE1 retail rules, not generic Unreal rules.

## UE2 / UE2.5 / UT2003 / UT2004

Use the exact supplied revision's `VerifyImport`/linker implementation. Do not carry the UT99 historical hacks forward unless that source retains them. UE2-family verification still derives provider, object, class and outer relationships from import/export tables, but later code must be treated as its own contract.

## UE3

UE3 `ULinkerLoad::Verify` verifies imports when runtime conditions allow. `VerifyImport`/inner verification resolves source linker/export and later code includes redirector/runtime handling. Static UnrealDB verification should implement the direct serialized identity match and only those source-backed fallbacks reproducible without runtime state.

## UE4 4.27.2

UE4 `FLinkerLoad::VerifyImport` explicitly wraps inner verification and may consult `UObjectRedirector`/active redirect mechanisms. Those redirects can depend on runtime configuration and loaded objects. The serialized direct-match contract remains package/object path + class identity + outer relationship; nonserialized redirects belong in the runtime-only category.

## UnrealDB contract

Return structured outcomes: resolved exact, resolved source-backed static fallback, missing provider, missing object, class mismatch, outer mismatch, non-public/inaccessible where applicable, runtime-only fallback unavailable, and malformed reference. Never silently turn a weaker name-only match into success.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation verification changes

| Revision | Source-confirmed change from the UT99 baseline |
|---|---|
| Unreal II | Removes active UnrealI/UnrealShare hash/provider/class-package compatibility. Retains exact object/class/provider matching, root-export outer acceptance, `Mesh -> LodMesh`, RF_Public, runtime native/transient binding, broad SafeReplace and forgiving mode. ClassRemap/PackageRemap remnants are disabled. |
| UE2.5 | Closely follows the reviewed Unreal II resolver; no active generic ClassRemap/PackageRemap or UnrealI/UnrealShare compatibility. |
| UT2003 | Retains exact match/root outer fallback/Mesh->LodMesh/runtime binding/SafeReplace; a package-remap retry exists in `StaticLoadObject`, not in `VerifyImport`. |
| UT2004 | Retains the same core resolver inventory; reviewed ClassRemap/PackageRemap paths are not active in import verification. |
| UE3 | Verification gains cooked/remapped-package conditions, import fixups, redirector handling and more runtime gates. Direct serialized matching remains separable from those runtime paths. |
| UE4 4.27.2 | Verification includes CoreRedirects, instancing/remapping, package privacy, script/native/in-memory handling, explicit package-name/external-package cases and modern outer rules. |

UnrealDB must choose the verification contract by exact revision, not accumulate every historical fallback into one resolver.
