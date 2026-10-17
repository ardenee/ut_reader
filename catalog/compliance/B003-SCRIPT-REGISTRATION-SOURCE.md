# B003 — UE5.8.3 script registration and nested public object audit

**Original source operation:** `UE5-583-SCRIPT-004`, one of two operations in **existing B003**. **Disposition: partial**, not a live engine runtime certificate. No new batch. `UE5-583-SCRIPT-005` and B002 sibling SCRIPT-003 are also still partial.

## Epic source contract and branch trace

Pinned `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`, SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`; pinned `Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h`, SHA256 `088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2`.

- **Source index and registration:** `FPackageObjectIndex::FromScriptPath` hashes a script UObject path as a 62-bit ScriptImport payload; `NotifyRegistrationEvent` invokes `GlobalImportStore.AddScriptObject` only at `ENotifyRegistrationPhase::NRP_Finished`, with an object expected. `FPathViews::Append` concatenates package/name; `ScriptObjects.Add(GlobalImportIndex, Object)` registers the live UObject.
- **Editor-only package identity:** `AddScriptObject` under `#if WITH_EDITOR` additionally hashes `PackageName` and associates its actual `GetOutermost()` UObject. This is compile-time, not inferred from optional-segment metadata.
- **Recursive nested public identities:** `ForEachObjectWithOuter(Object, ..., EGetObjectsFlags::IncludeNestedObjects)` visits nested objects. Only `HasAnyFlags(RF_Public)` objects are hashed, using `SubObject->GetPathName(nullptr,...)`, and inserted. Non-public descendants are not independently registered by this branch.
- **Enumeration/late registration:** `FindAllRuntimeScriptPackages` selects compiled-in `/Script/` and `/_Verse/VNI/` packages. `FindAllScriptObjects` enumerates public nested objects but for Verse VNI excludes non-UHT-generated objects. `RegistrationComplete` adds them under editor or *checks* initial registration without altering the map under `DO_CHECK` non-editor. The semantics are not interchangeable.
- **Lifecycle and parallel registry:** `RemoveUnreachableScriptObjects` prunes unreachable UObjects after GC; `FindScriptImportObject` uses `ScriptObjects.FindRef(GlobalIndex)`. Separate `NotifyScriptVersePackage` goes to `ScriptCells`, not the UObject map.
- **Object path hash:** Epic normalizes `.` and `:` to `/`, lowercases other TCHARs, runs CityHash64 over TCHAR bytes, then masks the highest two bits. This hash generation is in the separate original `SCRIPT-HASH-001` scope; we do **not** claim matching executable CityHash/TCHAR runtime behavior here.

`catalog/bin/verify-uedb5-b003-script-registration-source.php` pins both C++ files and checks **15** source behavior assertions, including nested/public branch, editor package identity, finish-phase-only mutation, GC cleanup and Verse VNI split. It then verifies an UnrealDB resolver cannot mistake a staged public export with the **same 62-bit value** for a registered ScriptImport; the result remains `unresolved`.

## UE5-D027 — lost script-import identity in SQL accelerator

Epic's registration and lookup use the script-import hash as the object identity, independently of provider/package public exports. Existing UnrealDB UEDB5 `dependency_results` retained `required_object_identity`, but `Uedb5DependencyProjectionBuilder::requiredObjectKey` explicitly returned `[null, null]` for `ScriptImport` and silently removed the script hash from `ue_uedb5_dependency_edges`. This made offline indexed/script dependency auditing impossible without opening each metadata file again.

**Correction:** new `Uedb5SqlProjectionContract::OBJECT_KEY_SCRIPT_IMPORT_HASH = 3`, distinct from `OBJECT_KEY_NAME = 1` and `OBJECT_KEY_PUBLIC_EXPORT_HASH = 2`. `requiredObjectKey` validates a **16-character / 62-bit** script hash, encodes eight exact binary bytes under key kind 3, and **does not populate** a package key, resolved file, resolved object or runtime-loaded state. An absent script identity remains NULL, and nonempty invalid/high-bit identities now fail closed.

**Historical differential and real data:**

- A before-change `2422faec` repository version of the SQL projection builder produces NULL key and NULL kind for a source-shaped ScriptImport identity. The new version produces kind **3** and exact eight-byte binary `167E29465232A0A9` for the same dependency, including **main, optional and dependency-bundle** variants.
- Authentic Satisfactory global cooked ScriptObjects chunk (TOC SHA256 `0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6`) contains global script object index `567E29465232A0A9` for `GetRecipes`, with 62-bit payload `167E29465232A0A9`. **A cooked diagnostic identity is not evidence the object is currently registered or live.**
- `catalog/bin/verify-uedb5-b003-script-hash-sql-projection.php` tests **20** independent assertions: Epic source pins, historical red/fixed green, positive/negative 62-bit ranges, malformed/hash-absent behavior, real authentic object hash, SQLite durable-writer projection, repeat publication and absence of fabricated providers.
- `catalog/bin/verify-uedb5-b003-script-hash-mysql-isolated.php` tests **9** assertions using the real PDO/MySQL publisher, **session-TEMPORARY shadow tables only**, exact HEX/length/kind, no package/provider, re-publish and transaction rollback. Persistent catalog data is never changed.
- `catalog/bin/verify-uedb5-b003-script-registration-boundary-acceptance.php` accepts **14** branch/projection boundary checks across **six independent executable drivers**, including prior B001/B002 package and ScriptImport boundary regressions.

Existing SQL projection rows produced before this correction **will not automatically acquire** the new key; bounded republishing from existing V5 metadata will be required only when that feature needs indexing. **No unsolicited production metadata scan, SQL backfill or V5 rewrite was performed.** The existing `VARBINARY(16)` and tinyint key-kind columns accept the 8-byte kind 3 without a schema migration; downstream keyed object-name / public-export searches retain their own kind filters.

## Closure boundary

Epic's in-memory UObject map, `NRP_Finished` events, object flags, Verse VNI/UHT decisions, loaded/unreachable state and GC are **not present in an offline cooked file**. UnrealDB cannot reconstruct a live registry from the global ScriptObjects diagnostic chunk. B003 SCRIPT-004 stays **partial** pending authoritative live registration/tracing or a runnable Epic-engine differential over the appropriate build/editor configurations. Its covered source branches and fixed offline SQL identity-loss issue are complete, not a claim of global Unreal Engine compliance.

Source-to-UnrealDB mapping: `Uedb5ZenPackageReader` decodes typed ScriptImport/FPackageObjectIndex, `Uedb5Ue5ZenDependencyResolver` returns unresolved with exact hash/no invented provider, `Uedb5DependencyProjectionBuilder` maps the script hash into explicit typed SQL identity, `PdoUedb5DependencyProjectionPublisher` persists those fields without false resolution. B002 SCRIPT-003 remains partial and is reciprocally linked to D027 because its projected offline ScriptImport identity was previously lost.

**Supplemental native source execution:** `catalog/bin/verify-uedb5-b015-native-source-execution.php` compiles seven exact Epic functions with test doubles in four configurations; see `catalog/compliance/B015-NATIVE-SCRIPT-SOURCE-EXECUTION.md`. This is **not a UE5 live-runtime session** and does not close any partial original operation.
