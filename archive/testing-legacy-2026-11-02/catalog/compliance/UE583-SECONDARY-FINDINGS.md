# UE5.8.3 secondary findings (deferred)

Only **out-of-primary-scope** discrepancies discovered while P01-P11 are underway belong here. Do not treat a finding in the active area as secondary.

| ID | Discovered during | Epic source branch | UnrealDB implementation | Impact | State |
|---|---|---|---|---|---|
| S001 | P01 | `CoreUObject/Private/UObject/ObjectResource.cpp`, `FObjectExport`: `E.ObjectFlags = Save & RF_Load` | Corrected: effective `objectFlags` uses Epic `0xA2D4083B` mask; separate `serializedObjectFlags` retained and V5-persisted. See `UE583-S001-RF-LOAD-CORRECTION.md` | Previously incorrect effective export flags | Resolved; mutation fixture and 119 V5 roundtrips pass |

Append S001, S002, etc. with precise expected/actual behaviour and original discovery task. Resolve after P11, except immediate containment of destructive risk.
