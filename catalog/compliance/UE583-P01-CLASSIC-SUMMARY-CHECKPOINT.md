# P01 classic package summary — source reconciliation checkpoint

Epic: `Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp` (pinned in UE583-SOURCE-DENOMINATOR.json).

- Legacy -9 format, serialized UE4/UE5/licensee components, saved-hash/version gates, optional offsets, custom versions, editor flags and summary tail compared with `UE5/UnrealPackageReader.php`. Source branch comparison remains in progress; **P01 is NOT closed**.
- Epic's `IsEnforcePackageCompatibleVersionCheck()` defaults to true (from `LinkerLoad.cpp` `GEnforcePackageCompatibleVersionCheck=1`) but is configurable; the standalone UnrealDB reader enforces the equivalent default unconditionally. No ungrounded loosening of future-version admission.
- **Production correction in this checkpoint:** Epic rejects obsolete package-level `CompressedChunks.Num() > 0` after reading `CompressionFlags` and compressed chunk table, invalidating the summary and returning. UnrealDB formerly continued parsing. Its UE5 classic reader now rejects a nonempty compressed chunk table before reading `PackageSource` or later summary fields.
- Additional production correction: Epic `FCompression::VerifyCompressionFlagsValid` (`Core/Private/Misc/Compression.cpp`, `Core/Public/Misc/CompressionFlags.h`) rejects bits outside mask `0xFFF` before reading the compressed chunks table. UnrealDB now applies that exact mask and rejects invalid values at the same point.
- Additional production correction: Epic clears `PKG_TransientFlags` (`PKG_NewlyCreated`, `PKG_IsSaving`, `PKG_ReloadingForCooker`, mask `0x40008001`) during summary load. UnrealDB now records raw `serializedPackageFlags` and exposes effective `packageFlags` with the transient mask cleared.
- Validation: PHP lint, classic reader contract and real classic V5 roundtrip all passed. Does not establish native decoded-field parity or closure of other P01 branches.

Secondary queue remains separate; this correction belongs to active P01.
