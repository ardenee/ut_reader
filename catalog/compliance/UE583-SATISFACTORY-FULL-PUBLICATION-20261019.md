# UE5.8.3 Satisfactory IoStore end-to-end publication and Pass 2

Fixture: unmodified Satisfactory Dedicated Server experimental TOC/UCAS family on PRD, retained with SHA256 verification into isolated DEV storage, C:/Temp/uedb5-iostore-retention-test. Database: 127.0.0.1:3339/uedb5_isolated_integration; guard checks both port and dedicated MySQL datadir; production PRD catalog is never written. The disposable test records use game ID 2 with temporarily selected UE5 profile 19 **only inside isolated DEV MySQL**. They are not actual production Unreal II files. Satisfactory game 14 remains empty by design in production.

## Completed end-to-end stages
- Actual 27,240-member `.utoc` indexed and retained with corresponding `.ucas`; container ready.
- Name indexing completed with no failures; all 27,240 package members report `metadata_status=ready` and `publication_status=published`.
- The isolated `ue_files` table contains 27,240 verified rows, and `ue_uedb5_files` has corresponding 27,240 V5 payloads.
- Prior bounded real-container extraction 5/5 SHA256, member reimport, full container backup/restore and published Zen snapshot integrity passed (see D033).
- Dependency Pass 2 `Uedb5GameDependencyPassService` preflight accepted `verified=27240`, `staged=27240`, `missing_primary_provider=0`, `invalid_staged=0`. The first 50-file real service run returned `succeeded=50, failed=0`. Direct `Uedb5MigrationValidator` check on persisted V5 file IDs 1–300 returned 300/300 `ready=true`, no errors.
- Full isolated Pass 2 **completed** on 19 October 2026: the initial 50 plus next 5,000 plus six disjoint worker partitions of 3,698 each = **27,240 succeeded, zero failures**, persisted policy `uedb5-dependency-pass-v13` and matching V5 payload hashes.

## Verified closure conditions and remaining broader gaps
- Full independent read-only V5 validation **completed** using `catalog/bin/verify-ue5-583-satisfactory-full-isolated.php`: 27,240 checked, **27,240 ready**, zero failures and zero exceptions; process exit 0 (499.98 seconds). Strict pinned Epic UE5.8.3 source verifier `verify-source-compliance.php --strict`: **18/18 registered operations**, zero issues, exit 0. These facts do **not** certify unrelated workflow/source reachability.
- Production schema migration `202610150002_iostore_package_names.php` not yet applied (read-only PRD credentials intentionally do not permit writes).
- `RECOVERY.json` `scope_complete=false`: 16 broader workflow paths have not been exhaustively reconciled to pinned Epic UE5.8.3 source; do not auto-close them from this fixture's success.
- Independent UE5 PAK v11 correction is covered by D034 with 4,761/4,761 production-helper extraction and Oodle parity.

Reproduce **full** V5 validation using `catalog/bin/verify-ue5-583-satisfactory-full-isolated.php` with the isolated storage root and guarded MySQL target. Source and DB fixture identity must remain pinned. Do not move temporary isolated game ID 2 rows into PRD.
