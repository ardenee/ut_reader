# Serialized Object Payload Boundaries

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

## Export-defined range

The export table defines an object's serialized payload through `SerialOffset` and `SerialSize`.

Before reading payload:

```text
SerialOffset >= 0
SerialSize >= 0
SerialOffset <= file/logical-package-size
SerialSize <= file/logical-package-size - SerialOffset
```

Use overflow-safe subtraction, not unchecked `offset + size`.

## Width changes

Field widths are versioned. UE4 `FObjectExport` reads legacy 32-bit SerialSize/SerialOffset before `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES` and 64-bit values afterward. Earlier engines use their own source-defined widths/compact encodings.

## Compression

When package compression maps logical offsets to compressed storage, SerialOffset/SerialSize remain logical package ranges. Resolve through the compression layer before physical reads.

## Conformance

Never scan until the next export to invent a size when SerialSize is present. Never impose arbitrary catalog size caps as format rules. Payload parsing must remain inside the export's declared logical range.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.
