# UE4.27.2 secondary findings queue

This list captures discoveries outside the currently active primary item. A recorded candidate **is not** a confirmed Epic difference until grounded in pinned source and the production caller. Work items stay deferred until U427-P01–P10 primary sequence is completed, except a safety regression that blocks the active primary acceptance.

| ID | Observation | Classification | Deferred disposition |
|---|---|---|---|
| U427-S001 | Retired UEDB4-only test scripts leave historical contracts broken (`audit-ue4-missing-verifyimport.php`, `CompactDependencyRebuilder`, package-name repair). | Audit harness / retired test compatibility, **not** proven Epic parsing mismatch | Reconcile under U427-P10 after primary source coverage |
| U427-S002 | V4-era UT4 v511 metadata assertions for missing selected physical provider differ; UT4 has separate clean-master source. | Out-of-scope older UT4 integration | Review under future UT4 source audit, not UE4.27.2 |

Add further findings here with stable U427-S IDs, source paths, production impact, and verification state. Do not expand U427-P01–P10.

## U427-S003 — resolved in P04: historical FString versus modern FName

During P02 source-defined linker FName index enforcement, some valid UE4.27 assets (including `TutorialTPP_PhysicsAsset.uasset` and `OculusControllerMaterial.uasset`) produced out-of-range integers in UnrealDB's existing soft-package-reference decoder. Import/export NameMap index validation remains strict; the soft-reference reader retains its previous behavior. **RESOLVED under P04**: ordinary pre-514 UE4 assets serialize these soft entries as FString while the installed 514+ assets serialize FName index/number pairs; UT4's independently pinned v511 profile retains FName. UnrealDB's former single FName decoder retained 112/128 entries. `UE427-P04-NATIVE-DEPENDENCY-CLOSURE-20261020.md` records the source/corpus evidence and passing native differential for all 128/128 paths and offsets, with 1,749/1,749 real assets still passing. U427-S003 is retired; it does not add a primary audit operation.
