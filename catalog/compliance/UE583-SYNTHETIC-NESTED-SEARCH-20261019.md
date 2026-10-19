# UE5.8.3 synthetic nested-object search acceptance — 19 October 2026

**Test:** `catalog/bin/verify-ue5-583-synthetic-nested-search.php`, exit 0, `ok=true`.

The retained 27,240-member genuine Satisfactory Zen dataset contained **no** normalized dotted nested export/import object paths, confirmed by exhaustive inventory `UE583-SATISFACTORY-NESTED-PATH-INVENTORY-20261019.md`. This independent **synthetic functional fixture** fills that specific coverage gap without modifying the original real-file dataset.

Emulated UE5-classic package `/Game/Test/Building` has export `outer_index` hierarchy:
`Building`, `Building.Door`, `Building.Door.Handle`, `Building.Window`, `BuildingExtra`, `BuildingExtra.DoorX`.

The fixture writes a canonical .uedb5 using **production** `Uedb5MetadataSnapshotWriter`, reads it using `Uedb5MetadataReader`, verifies all six `Uedb5ClassicDependencyResolver::exportCoverageRows` paths, then invokes the production `Uedb5CatalogMetadataSearch::matchSnapshot` method via reflection. The latter invocation is intentional to test the actual production comparison method without inserting synthetic `ue_files` or name projection rows into any database. Test storage uses a random disposable directory under `C:/Temp`, recursively cleaned in `finally`.

**Passed checks (6/6):** `Building.Door` matches Door and Door.Handle; `Building.Door.Handle` matches only Handle; `Building.Window` matches Window; `Building.DoorX` matches nothing; `BuildingExtra` matches only its hierarchy; `/Game/Test/Building.Building.Door` matches exactly the two fully qualified paths. Delimiter boundary exclusions prevent `DoorX` or `BuildingExtra` appearing under `Building.Door`.

**Coverage distinction:** This **closes synthetic V5 roundtrip and functional nested export-path matcher behaviour**, not a complete end-to-end SQL candidate discovery / HTTP search-route acceptance test, and not independent Epic-produced fixture parity. The old legacy-static `verify-search-descendant-prefix-contract.php` remains outdated, and is not claimed as passed. The overarching source/workflow register `RECOVERY.json.scope_complete=false` remains unchanged. No production Satisfactory import and no PRD database changes.
