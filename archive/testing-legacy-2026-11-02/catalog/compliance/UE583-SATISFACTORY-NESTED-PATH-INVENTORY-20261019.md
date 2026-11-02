# UE5.8.3 — Exhaustive Satisfactory Zen nested-path inventory (19 October 2026)

Executed `catalog/bin/verify-ue5-583-satisfactory-nested-path-inventory.php` (initially at `C:/Temp/ue583-scan-all-nested-paths.php`) on DEV against dedicated **isolated, guarded** MySQL `127.0.0.1:3339/uedb5_isolated_integration` and immutable Satisfactory source V5 snapshots in `C:/Temp/uedb5-iostore-retention-test`. The scan enumerates all `ue_files WHERE game_id=2` and reads their authoritative persisted V5 snapshots. No PRD database or live Satisfactory catalog changes.

Final process exited **0** with exact result:
- **27,240 / 27,240 real Satisfactory Zen package V5 snapshots read**
- **0 nested export local paths** (`Uedb5ClassicDependencyResolver::exportCoverageRows`, where normalized `local_path` contains `.`)
- **0 nested import dependency paths** (`Uedb5ParityV5ReadService::dependencies`, where `required_object_path` contains `.` *after the normalized required package prefix is removed*)
- **0 read exceptions**

The previous 5,000-file subset is superseded by this complete inventory. This is strong evidence that **this specific genuine Satisfactory snapshot set provides no hierarchical nested-object path fixture suitable for descendant-prefix search testing**. A dot in the full `/Game/...Package.Object` syntax is not itself nested object hierarchy.

The earlier exact V5 FName/export object/negative search tests already passed. **Do not mark hierarchical descendant-path search verified simply because no nested-path examples exist**. It requires a different authentic UE5 asset carrying a nested export/import object path or a controlled fixture with independent Epic source reference behaviour.

The available complete search results do **not** establish an UnrealDB implementation defect and do not justify modifying production search logic. `RECOVERY.json.scope_complete=false` remains unchanged. UE4.27.2 and production Satisfactory import remain untouched.
