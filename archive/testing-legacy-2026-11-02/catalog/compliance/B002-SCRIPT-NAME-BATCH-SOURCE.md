# B002 / UE5-583-SCRIPT-002 — cooked ScriptObjects NameBatch

**Original B002 operation:** `UE5-583-SCRIPT-002`. **Accepted scope:** offline serialized global cooked NameBatch decoding and name-map indexing. The sibling original `UE5-583-SCRIPT-003` is still partial; **B002 as a whole is not globally verified**. No new B-number, discrepancy or parser change was invented.

## Epic source evidence

- Pinned `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`, SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9`, `FCookedScriptObjectsDebug::LoadDebugData`: load `NameMap = LoadNameBatch(Ar)`, then `NameMap[Entry.Mapped.GetIndex()].ToName(Entry.Mapped.GetNumber())`.
- Pinned `Engine/Source/Runtime/Core/Private/UObject/UnrealNames.cpp`, SHA256 `1a4f7f23564afebc8c18d080a4757b16958c15fcab513c9e3a1f0a67e565f67c`, `FSerializedNameHeader` lines 4803–4830 and `FNameBatchLoader` lines 5315–5405: the serialized batch starts with `uint32 Num`; **zero names returns before the additional fields**. For nonzero `Num`: `uint32 NumStringBytes`, `uint64 HashVersion`, one serialized `uint64` hash per name, one two-byte header per name (UTF-16 flag/15-bit name length), and concatenated UTF-8 or UTF-16LE name strings. Source may discard saved hashes and rehash inside the live `FNamePool`; output name positions are maintained in either the input-order or shard-loading branches.
- Pinned `Engine/Source/Runtime/Core/Public/UObject/NameBatchSerialization.h`, SHA256 `63fa10b5509a03cd9c55e836b5ab2eaa1522bd7a43e4564b96a5de80a9c7c979`, `LoadNameBatch(FArchive&)`.
- Pinned `Engine/Source/Runtime/Core/Public/Serialization/MappedName.h`, SHA256 `5221b85d9a40d300607e13d7146900e88a23d0894650296497014ec4f0f2787a`, 30-bit `GetIndex`, separate type and numeric FName number.

## UnrealDB mapping and observations

`Uedb5ZenPackageReader::readNameBatchForScriptObjects()` delegates to the existing `readNameBatch` decoder (no change required). It preserves per-index serialized hashes and version metadata as *evidence*, decodes both string encodings, retains raw strings, and checks declared offsets and string lengths. It does not recreate Epic's mutable live name pool, shard hash-consensus or editor state. The only direct production consumer of this ScriptObjects-specific entry point is `Uedb5ZenScriptObjectsReader.php`; the auditor enumerates all `catalog/src` PHP files on each host (PRD observed 559, DEV observed 590), fails for unanticipated extra consumers and does not treat one host's count as a global constant.

**Authentic evidence:** same installed Satisfactory dedicated-server global IoStore chunk as B001. TOC SHA256 `0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6`. The batch decodes **50,640 names**, consumed by **54,968** cooked ScriptObjects, and the first `FMappedName` (type 2/index 0/number 0) resolves to `GetRecipes`. Full B001 authentic test separately verifies every mapped index and the entire fixed-size record set. No copyrighted game binary is checked into GitHub.

`catalog/bin/verify-uedb5-b002-name-batch-source.php` independently anchors all four Epic files, verifies loader branches and field layouts, positive ASCII + Unicode and zero-name fixtures, truncated and corrupt-length negatives, authentic name count and first mapped entry, and the complete caller inventory (**18 checks**).

`catalog/bin/verify-uedb5-b002-operation-acceptance.php` re-runs that source oracle, the real cooked B001 fixture, B001's operation-level acceptance, malformed standalone ScriptObjects and the existing Zen package-reader regression (**five drivers, 11 named assertions**).

**Conclusion:** no difference found within **source-defined offline cooked global NameBatch wire decoding/indexing**; implementation stayed unchanged and is bound to source SHA256 `6b79d5a8465d01bbe784f06aaf07f0033e9dc84300d6761b135eb29c74b871da`. Do **not** infer Epic's live FNamePool lookup, in-memory shard/cache state, UClass/CDO registration or script runtime resolution from this acceptance. Those behaviors remain separate original operations, including B002 sibling SCRIPT-003.
