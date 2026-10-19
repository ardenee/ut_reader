# UE5.8.3 workflows 04–06 — dependency acceptance

**WF04 discover_dependencies ACCEPTED.** Production `Uedb5GameDependencyPassService` consumed 27,240 Satisfactory V5 packages with complete Pass 2, zero failures. Native Zen decoded reference identities matched 100 real packages/6,546 fields; native classic DependsMap matched 140 packages/9,069 exact references. `verify-uedb5-ue5-zen-dependency-resolution.php` and B004 operation acceptance passed.

**WF05 select_physical_providers ACCEPTED.** Production `PdoUedb5PhysicalProviderSelector` and effective/mounted package store contexts follow Epic backend priority and terminal status rules. Native Epic FilePackageStore matched 70 status, priority, redirect, missing and unmount recovery fields. Backend source oracle, arbitration and B005 operation acceptance all passed; isolated SQL providers were exercised on genuine V5 data.

**WF06 resolve_object_references ACCEPTED.** Production `Uedb5DependencyRebuilder` correctly resolves stored Zen index kinds, import/export hashes and optional main-first identities. B004, object-index source oracle, Zen dependency resolution and isolated 27,240-member Pass 2 passed. Invalid indexes and contradictory provider evidence are rejected.

Evidence: `UE583-SATISFACTORY-FULL-PUBLICATION-20261019.md`, `UE583-EPIC-NATIVE-ZEN-HEADER-FIELDS-20261019.md`, `UE583-P02-EPIC-NATIVE-DEPENDS-IDENTITIES-20261019.md`, `UE583-P07-EPIC-NATIVE-FILESTORE-MOUNT-20261019.md`. Scope is UnrealDB metadata/dependency and SQL projection, not live UObject execution. No production DB writes.
