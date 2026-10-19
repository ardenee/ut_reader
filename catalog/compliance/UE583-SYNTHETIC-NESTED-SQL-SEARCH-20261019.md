# UE5.8.3 — SQL-backed synthetic nested search acceptance (19 October 2026)

**Passed**: `catalog/bin/verify-ue5-583-synthetic-nested-sql-search.php`, exit 0, six of six verified query cases.

Unlike the prior matcher-only test, this test uses the actual `Uedb5CatalogMetadataSearch::findMatches` production query and its compact SQL name candidate discovery, followed by authoritative V5 snapshot hydration and path comparison. It writes a disposable synthetic UE5 classic V5 snapshot with `Uedb5MetadataSnapshotWriter` under a unique `C:/Temp` directory, then creates **session-scoped MySQL TEMPORARY shadow tables** with the exact production table schemas for `ue_files`, compact name dictionary and candidates, compact objects, dependency edges, and aliases. A single synthetic verified file and compact name candidates are inserted into the temporary tables, never persistent tables. Temporary tables disappear when the isolated MySQL connection closes, and the test V5 directory is removed in a `finally` block.

The guarded connection requires DEV isolated MySQL port 3339 with the specific isolated datadir. The actual 27,240 Satisfactory file records and all production DBs remain untouched.

Passed actual SQL-backed queries:
- `Building.Door` → `Building.Door`, `Building.Door.Handle`.
- `Building.Door.Handle` → Handle only.
- `Building.Window` → Window only.
- `Building.DoorX` → zero hits (reject textual prefix false positives).
- `BuildingExtra.DoorX` → only that independent hierarchy.
- `/Game/Test/Building.Building.Door` → exactly two correctly qualified descendants.

The initial test incorrectly expected the dot-free string `BuildingExtra` to perform hierarchical-prefix expansion. Production intentionally dispatches dot-free queries to **exact-name mode**; correcting this contract expectation yielded six passing tests, with no production search code change.

**Acceptance boundary:** Synthetic UE5 V5 roundtrip + production SQL candidate discovery + path matching have now been tested together. This **closes functional nested-export descendant search for the modeled hierarchy**; it does not establish independently cooked Epic nested-package parity, import descendant parity, or the external web HTTP search route. The legacy static SQL-string verifier checks an obsolete search implementation and remains outdated. The UE5 global compliance scope is not yet marked complete.
