# P08–P11 — IoStore/PAK primary sequence (2026-10-19)

Pinned Epic `Core/Private/IO/IoStore.cpp`, internal IoStore structures and `PakFile/Private/PakFile.cpp` are covered by SRC-008–SRC-011 and the previously retained independent Epic differentials. Do **not** repeat the 27,240-package Epic Zen extraction or whole-file scan.

P08 SRC-008: TOC v2/v5 legacy metadata and v8 latest fields, known chunk IDs/offset-length, directory, perfect-hash/overflow and trailing-extension semantics are covered by `verify-uedb5-ue5-iostore-foundation.php` and B006 pinned-source tests (exit 0). **Still partial**: no independent Epic decoded per-field oracle for *all* TOC sections. Two optional broader standalone tests did not pass because their local retained fixtures were unavailable or not ready (`C:/Temp/uedb5-iostore-retention-test/...FactoryGame-WindowsServer.utoc` absent and indexed container not ready); those are fixture prerequisites, not successful tests.

P09 SRC-009: existing independent Epic `UnrealPak`/Zen full content differential includes 27,240 real IoStore packages with SHA equality; reconstruction, compression/encryption alignment, partition offsets and cross-block slicing retain **verified** status. No costly new scan run.

P10 SRC-010: native Epic PAK11 independent index differential is retained in `UE583-PAK-EPIC-INDEPENDENT-DIFFERENTIAL-20261019.md`; `verify-ue5-583-pak11-real-satisfactory.php` passed on DEV. Existing verified status retained within explicit supported layout constraints.

P11 SRC-011: real PAK11 test passed and existing Epic native payload differential retained, including compression-block/payload byte content. B011 and B016 operation acceptance initially reported failure when the source-root environment variable was unset, then **both passed** with `UNREALDB_EPIC_SOURCE_ROOT=L:/Source/Engine/UE5/UE 5.8.3`. No code discrepancy proven by the initial environment-only failure. Verified status retained.

Primary P01–P11 review sequence is complete; denominator is still **6 verified / 5 partial** (SRC-002, SRC-003, SRC-004, SRC-007, SRC-008). This is *not* overall Epic compliance acceptance. Follow fixed plan secondary findings, then existing 16 production workflow acceptances and final strict certification; UE4.27.2 remains blocked.
