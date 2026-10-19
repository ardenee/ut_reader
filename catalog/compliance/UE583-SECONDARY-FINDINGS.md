# UE5.8.3 secondary findings (deferred)

Only **out-of-primary-scope** discrepancies discovered while P01-P11 are underway belong here. Do not treat a finding in the active area as secondary.

| ID | Discovered during | Epic source branch | UnrealDB implementation | Impact | State |
|---|---|---|---|---|---|
| S001 | P01 | `CoreUObject/Private/UObject/ObjectResource.cpp`, `FObjectExport` deserializer: `E.ObjectFlags = Save & RF_Load` | `UE5/UnrealPackageReader.php::readExports()` currently retains raw `u32` as effective `objectFlags`; compare persisted use and apply Epic mask with raw-field provenance after P11 (P02 source scope) | Potential export flag semantic mismatch | Deferred after P11 |

Append S001, S002, etc. with precise expected/actual behaviour and original discovery task. Resolve after P11, except immediate containment of destructive risk.
