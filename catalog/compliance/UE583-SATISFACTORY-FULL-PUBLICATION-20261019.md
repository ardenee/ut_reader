# UE5.8.3 Satisfactory IoStore end-to-end publication and Pass 2

Fixture: unmodified Satisfactory Dedicated Server experimental TOC/UCAS family on PRD, retained with SHA256 verification into isolated DEV storage, C:/Temp/uedb5-iostore-retention-test. Database: 127.0.0.1:3339/uedb5_isolated_integration; guard checks both port and dedicated MySQL datadir; production PRD catalog is never written. The disposable test records use game ID 2 with temporarily selected UE5 profile 19 **only inside isolated DEV MySQL**. They are not actual production Unreal II files. Satisfactory game 14 remains empty by design in production.

## Completed end-to-end stages
- Actual 27,240-member `.utoc` indexed and retained with corresponding `.ucas`; container ready.
- Name indexing completed with no failures; all 27,240 package members report `metadata_status=ready` and `publication_status=published`.
- The isolated `ue_files` table contains 27,240 verified rows, and `ue_uedb5_files` has corresponding 27,240 V5 payloads.
- Prior bounded real-container extraction 5/5 SHA256, member reimport, full container backup/restore and published Zen snapshot integrity passed (see D033).
- Dependency Pass 2 `Uedb5GameDependencyPassService` preflight accepted `verified=27240`, `staged=27240`, `missing_primary_provider=0`, `invalid_staged=0`. The first 50-file real service run returned `succeeded=50, failed=0`. Direct `Uedb5MigrationValidator` check on persisted V5 file IDs 1–300 returned 300/300 `ready=true`, no errors.
- Expanded isolated Pass 2 batch of up to 5,000 files started 19 October 2026. **Do not count it complete from launch or intermediate records.** Subsequent query must check `ue_uedb5_migration_status` `dependency_policy=uedb5-dependency-pass-v13` and matching payload hash. Follow with full V5 validation and any failure repair.

## Still open
- End-of-run dependency count, failure count and full 27,240 V5 validation. Full-game Pass 2 is not complete merely because member publication is complete.
- Production schema migration `202610150002_iostore_package_names.php` not yet applied (read-only PRD credentials intentionally do not permit writes).
- `RECOVERY.json` `scope_complete=false`: 16 broader workflow paths have not been exhaustively reconciled to pinned Epic UE5.8.3 source; do not auto-close them from this fixture's success.
- Independent UE5 PAK v11 correction is covered by D034 with 4,761/4,761 production-helper extraction and Oodle parity.

Reproduce first-50 V5 validation using the standalone `Uedb5MetadataReader` and `Uedb5MigrationValidator` with the isolated storage root. Source and DB fixture identity must remain pinned. Do not move temporary isolated game ID 2 rows into PRD.
