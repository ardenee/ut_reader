# Compact Index Encoding

## Scope and authority

This is a cross-generation UnrealDB semantic specification. It does **not** flatten UE1, UE2/2.5, UE3 and UE4 into one binary layout. The exact per-generation package specifications remain authoritative for serialized order and version gates.

Authoritative source families used by this specification:

- UT99 retail v1.400: `ardenee/UT99src`, especially `Core/Inc/UnArc.h`, `UnObjBas.h` and package/linker source.
- Unreal II / UE2 and UE2.5: `ardenee/unreal2src` and `ardenee/UE2.5`; behavior is used only where present in those source trees.
- UT2003/UT2004: their supplied game source trees for revision-specific UE2 branches.
- UE3 UDKUltimate: `ardenee/UE3src`, especially `Development/Src/Core/Inc/UnLinker.h`, `UnFile.h`, `UnObjBas.h`, and `Core/Src/UnLinker.cpp`.
- UE4 4.27.2: `ardenee/UnrealEngine4`, especially `Core/Public/Serialization/Archive.h`, `CoreUObject/Public/UObject/PackageFileSummary.h`, `ObjectResource.h`, and their implementations.

Rules:

1. use the package's own engine/revision rules;
2. never use a later generation's field layout to repair an earlier package;
3. version/licensee/custom-version gates are format rules, not optional hints;
4. byte ranges and counts must be checked against the actual file without imposing arbitrary UnrealDB format limits;
5. runtime/config behavior not serialized in the file must not be guessed.

## Scope

Compact indices are an early Unreal persistent integer encoding. UT99 declares `FCompactIndex` and `AR_INDEX(intref)`; fields explicitly serialized through that wrapper use the compact representation. It is not a universal integer encoding.

## Encoding

The first byte contains six value bits, a continuation bit and a sign bit. Continuation bytes contribute seven value bits each and carry a continuation bit, with the fifth/final byte contributing the remaining high bits required for the signed 32-bit domain.

The sign is encoded separately from the magnitude. Decode into a signed 32-bit semantic value only after assembling the magnitude. Reject overlong/truncated encodings rather than reading beyond the package.

## Generation boundary

UE1 uses compact indices in package structures and object streams where the source invokes `AR_INDEX`. UE2 revisions must follow their own source call sites. UE3's linker defines `PACKAGE_INDEX` as fixed `INT`; UE4 uses `FPackageIndex` backed by fixed `int32`. Do not apply UE1 compact decoding to UE3/UE4 package indices.

## Conformance

The field's serialization operator decides compact versus fixed width. UnrealDB must never infer compact encoding merely because a value is small.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed use/change |
|---|---|
| Unreal II | Compact index algorithm is retained. Export `ClassIndex`, `SuperIndex`, `SerialSize` and conditional `SerialOffset` are compact, while import/export `PackageIndex` is fixed-width INT. `FString` count is compact. |
| UE2.5 | Retains the same important compact-vs-fixed distinction as the reviewed UE2 lineage. |
| UT2003 | Retains the 1-5 byte signed compact algorithm; `PackageIndex` remains fixed INT while export class/super/serial fields and name/object references use the source-defined compact paths. |
| UT2004 | Retains UE2-era compact-index serialization for the corresponding fields. |
| UE3 | Package/object indices are fixed 32-bit and `FString` count is fixed 32-bit. The UE1/UE2 compact encoding must not be used for these UE3 structures. |
| UE4 4.27.2 | `FPackageIndex` is backed by fixed int32; modern package structures do not use the UE1/UE2 compact-index scheme for package references. |

The transition to fixed package indices is therefore a real generation change, not merely an implementation detail.
