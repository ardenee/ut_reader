# Original engine dependency projection epoch — PRD read-only checkpoint

**3 November 2026 — NOT APPLIED.** This is the rollout boundary for the
existing fixed 32 implemented UE1–UE5 code paths, **not a new test register**.

## What changed

Previous uedb5-dependency-pass-v13 SQL projections were published before
source-backed original physical provider/mount and static import fixes across
UE1–UE4. A registered V5 payload SHA256 can still match while its old
dependency outcomes are no longer source-correct. The current Pass 2 now
selects these independent policies:

- UE1: uedb5-dependency-pass-ue1-mount-v14 (already introduced in UE1).
- UE2: uedb5-dependency-pass-ue2-mount-v14.
- UE3: uedb5-dependency-pass-ue3-mount-v14.
- UE4: uedb5-dependency-pass-ue4-mount-v14.
- UE5: uedb5-dependency-pass-ue5-mount-v14.

**Exact snapshot payload SHA and engine policy** are both required before
a result is presented as current by the game missing-dependency summary,
file examiner Requires/Required By, V5 parity dependency reader, and
cross-game dependency examiner. A stale status is withheld, not deleted.

## Genuine production inventory (read-only)

| Engine | Game IDs | Verified/staged | Current v14 | Old v13 |
|---|---|---:|---:|---:|
| UE1 | 3, 12 | 85,091 | 0 | 85,091 |
| UE2 | 2, 4, 5 | 79,179 | 0 | 79,179 |
| UE3 | 6 | 6,722 | 0 | 6,722 |
| UE4 | 7 | 64,247 | 0 | 64,247 |
| UE5 | 14 | 0 | 0 | 0 |
| **Total** | | **235,239** | **0** | **235,239** |

Read-only PRD queries originated from DEV in MySQL READ ONLY transactions.
Original UT4 file #1353254 retains all 527 original v13 edges,
including 174 stored resolved and 174 package_only statuses. Source-correct
original mount selection is not authenticated, so current website
displays must not present these old outcomes as current.

Actual production read-only acceptance: independent policy+payload
freshness for all eight game IDs, UT4 V5 dependency reader stale
rejection, UT4 Required By SQL suppression, cross-game stale rejection
and preservation of 527 old UT4 SQL edges. The genuine original UT4 file\n#1353254 also passed the actual duplicate-upload recovery path: its valid\nmetadata and stale v13 dependency status result in a duplicate outcome with\ndependency_projection_stale=true, not a source reimport or a repair job. Evidence:
tests/evidence/ue5/ue5-500-vs-583-zen-struct-and-all-engine-policy-epoch-20261103.json.
No original package, DB row, metadata container, IoStore package, schema
or indexed original source was written or regenerated during this review.

## Deferred execution gate (do not run automatically)

Before any actual per-engine Pass 2 regeneration, obtain explicit production
approval and verify read/write backup/restore, PRD-local credentials,
exact game ID and original mount-context evidence. Cut over each engine
independently after source-verified canaries and compare recomputed
UEDB5 edges, SQL publication, payload SHA256 and website results.
Never UPDATE dependency_policy without rebuilding actual source-correct
dependency edges. Without authenticated original mount order, original
classic provider resolution must remain unresolved.

The already staged UE1-only PRD-local script remains separate. Its verifier
now checks correct UE2/UE3/UE4 game mappings and that no UE1-only policy is
written under another engine. UE2–UE4 have **no authorized bulk job in
this batch**. Satisfactory game14 has an active UE5 profile but zero
registered IoStore containers, packages or verified files in PRD.

**32/32 source-verified code paths do not imply 235,239 republished
production results or native certification for every intermediate UE build.**
