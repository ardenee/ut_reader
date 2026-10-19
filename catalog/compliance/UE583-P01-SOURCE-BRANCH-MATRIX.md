# P01 — UE5.8.3 classic summary branch-by-branch checkpoint

Source: pinned `PackageFileSummary.cpp` `operator<<(FStructuredArchive::FSlot, FPackageFileSummary&)` and `PackageFileSummary.h`, `LinkerLoad.cpp`; implementation: `UE5/UnrealPackageReader.php::parse()`.

| Source-defined branch | UnrealDB handling | Status |
|---|---|---|
| Tag validation and swapped endian | `PACKAGE_FILE_TAG`, `PACKAGE_FILE_TAG_SWAPPED`, `setByteSwapping` | matched implementation branch; native semantic comparison outstanding |
| Legacy file version, -9 current vs future legacy | rejects `< -9`; standalone UE5 parser excludes pre-UE5 legacy `> -8` | aligned with current modern reader scope; legacy older files belong to appropriate reader |
| UE4/UE5/licensee and unversioned summary | serialized fields retained; runtime assumed UE4 522/UE5 1018; unversioned captured | runtime/editor unversioned admission caveat below |
| Upper compatible version | Epic console-variable `s.EnforcePackageCompatibleVersionCheck` defaults enabled; UnrealDB rejects higher versions | matches default only; configurable opt-out intentionally not implemented |
| Saved hash, custom versions, header-size, name and summary counts/offsets | decoded by version branches in `parse()` | branch implementation present; native field oracle outstanding |
| Editor-only localization and summary optional fields | guarded by `PKG_FilterEditorOnly` and source version constants | branch implementation present; native field oracle outstanding |
| Transient package flags | clears `PKG_TransientFlags` on load, preserves raw serialized value | **production corrected in f4823913** |
| Compression flags | `FCompression::VerifyCompressionFlagsValid` bitmask 0xFFF | **production corrected in 458f7620** |
| Obsolete compressed-chunk array | rejects nonempty array before further summary parse | **production corrected in 4750cd2c** |
| Generations, engine versions, package source, cooked lists, bulk/chunk/preload offsets, payload and resource offsets | conditioned reads in `parse()` | source branches accounted in code; independent field oracle outstanding |

Scope cautions: Epic's editor can reject unversioned content when `GAllowUnversionedContentInEditor` is disabled; UnrealDB is an offline metadata reader, not an editor. Epic also offers configurable future-version policy; UnrealDB uses its enabled-by-default policy for safe metadata decoding. These are documented differences in consumer policy, not evidence of identical engine execution.

**P01 not closed:** independently decoded classic summary semantic oracle and complete negative/error-path differential remain open. P02 source issues discovered during P01 are sent to the secondary queue, not worked out of sequence.
