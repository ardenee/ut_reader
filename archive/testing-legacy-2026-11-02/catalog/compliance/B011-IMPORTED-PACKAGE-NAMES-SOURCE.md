# Original B011 — imported-package name serialization and decoding

**Operation:** `UE5-583-IMPORTED-PACKAGE-NAMES-001` — VERIFIED after source-first correction D014.

## Epic UE5.8.3 source anchor

- `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp:173–207`, SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`. The container serializes a **name batch first, then one separate signed int32 `FName::GetNumber()` per entry**. The reader calls `LoadNameBatch` and then `NameEntries[Index].ToName(Number)`. The wire is not interleaved name/number pairs.
- `ZenPackageHeader.cpp:105–109`, SHA256 `954117a45b9a802d3f289b30f2a0e191a7bb82cdcb107a22fef134a73172995b`: creates a view from `ImportedPackageNamesOffset` through `HeaderSize` and deserializes the name container. **There is no requirement to consume every final byte** of that view.
- `AsyncLoading2.cpp:7160–7176`: imported names can be absent or the count matches the imported package IDs; absent names yield `NAME_None` rather than invented names.

## UnrealDB source-to-code mapping

`Uedb5ZenPackageReader::parse` delegates once to `readImportedPackageNames` for the main or optional header. The implementation reuses the name-batch decoder (ANSI/UTF16) and separately consumes one signed int32 number per name. It enforces bounds and retains `number` plus `display_text`, with the full decoded rows copied to the V5 imported-name section by `Uedb5Ue5ZenIoStoreSnapshotBuilder`. The dependency resolver uses associated package import ID/hash fields; redirection and runtime package instancing remain separately tracked original obligations.

**D014 demonstrated and fixed:** UnrealDB used to reject any additional bytes after the final imported-name number, despite Epic's deserializer not checking the reader's final position. The independent source-shaped fixtures failed in two cases **before** correction (`trailing_header_padding`, `zero_names_with_padding`) and pass afterwards. Commit `a9b4b3cf61750521dfe04496684d17cdc5f9531b` removes only this extra end-of-view rejection; truncated number, malformed name batch and out-of-bounds sections remain errors.

## Independently executable acceptance

- `verify-uedb5-b011-imported-name-source.php` verifies both pinned Epic file hashes, loader control-flow anchors, 18 source/wire/error assertions, ANSI/UTF16 and varied number values, empty name arrays, extra bytes and malformed spans.
- `verify-uedb5-ue5-zen-package.php` checks an actual constructed temporary IoStore header and its import map, then increases `HeaderSize` and inserts four unconsumed header bytes before the export payload; preserved imported name/provider identity and intact payload size are asserted.
- `verify-uedb5-b011-operation-acceptance.php` composes source, wire/V5 and dependency-consumer drivers into nine named, mandatory assertions. The formal compliance verifier reruns it and fails if source, implementation or caller gates cease to match.
- No Epic editor binary, production metadata scan or persistent database write was required.

**Boundary:** This verifies serialized imported-package **name metadata** and its safe use in the V5 parser. It does not certify complete runtime package-name redirection, instancing, mount arbitration or UObject loading; these have separately registered original source obligations. Do not extrapolate B011 acceptance to global UE5 compliance.
