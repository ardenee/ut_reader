# B001 — UE5.8.3 cooked ScriptObjects diagnostics source audit

**Original operation:** `UE5-583-SCRIPT-001`. **Disposition: partial.** This is a source-defined parser correction and reproducible negative-before/positive-after evidence; it is not a verified complete runtime ScriptObjects operation.

## Pinned Epic reference and boundaries

- `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`, `FScriptObjectEntry` serializer (line 238), `FCookedScriptObjectsDebug::LoadDebugData` (lines 6983–7019).
- `Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h` SHA256 `088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2`: mapped name + GlobalIndex + OuterIndex + CDOClassIndex.
- `Engine/Source/Runtime/Core/Public/IO/IoChunkId.h` SHA256 `64ebf25a5cf436e1cc353a8fefbd16bbb231ef28ff914a4ef6296e27b20b30b1`: ScriptObjects chunk enum value 5.
- `LoadDebugData` exits if the global chunk does not exist, loads a name batch, reads signed `int32 NumObjects`, then constructs a contiguous view of **NumObjects** `FScriptObjectEntry` items starting at `Ar.Tell()`. It does **not** check that the chunk ends at the last viewed entry. The debug map is not the live object registry, and actual diagnostic timing is gated on compiled-in UObject registration.

## UE5-D024 — extra trailing-byte rejection in UnrealDB

Before `2ab7af16`, `Uedb5ZenScriptObjectsReader::parse()` required `remaining() === NumObjects * 32`, rejecting trailing bytes never rejected by Epic's stated `LoadDebugData` branch. The corrected reader requires only `remaining() >= NumObjects * 32` and retains a configured max record count; truncated records, invalid mapped-name indices, and duplicate identities are still rejected conservatively. It never claims a diagnostic cooked record proves a runtime object.

The source-pinned `catalog/bin/verify-uedb5-b001-script-objects-source.php` passes 12 explicit checks, independently executing the original `2b4377b0` class alongside corrected code on identical bytes. Old code rejects a valid trailing-byte fixture; new code accepts it. Zero-records-with-trailing-data also accepted, truncated record rejected. The separate standalone fixture checks three malformed inputs. No production database or live editor/UE runtime was modified.

**Remaining B001 closure work:** pin complete source call/use inventory, source-defined name-entry mapping/duplicate behavior, test retained authentic cooked ScriptObjects chunk if present, test fromToc selector over retained complete container family, and verify which diagnostic information is and is not consumed by runtime script registrations. Neither the presence of a cooked debug record nor its CDO index establishes live object ownership. B001 stays partial until these gates are accepted.
