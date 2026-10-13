# Compact V5 candidate projection implementation — 2026-10-13

The initial code now provides `Uedb5CompactCandidateProjectionBuilder`, a pure source-backed conversion of the existing V5 name/object SQL projection into compact dictionary-ID candidate records. It validates dictionary SHA-256 fingerprints, raw MD5, normalized text, source snapshot row/index identity and file ID, and refuses invalid/missing/tampered mappings rather than selecting ambiguous hash matches. It does not modify or use live SQL tables.

`verify-uedb5-compact-candidate-source-contract.php` passed 7/7 rejection and positive regression cases on PRD. `verify-uedb5-compact-real-snapshot-samples.php` passed 7/7 real UEDB5 samples, one from each game, using V5-derived search keys as the virtual dictionary. These are targeted examples, not comprehensive Epic-source signoff.

Remaining implementation steps: introduce production-compatible compact tables/schema, transactional publisher and read/search adapters; test actual DB dictionary ID assignment with concurrency and global collisions; verify bulk import, resync, delete and restore; demonstrate query parity at scale; design resumable migration/rollback and reclaim old physical candidate tables only once verified. No production schema or SQL rows changed at this checkpoint.
