# UE4.27.2 secondary findings queue

This list captures discoveries outside the currently active primary item. A recorded candidate **is not** a confirmed Epic difference until grounded in pinned source and the production caller. Work items stay deferred until U427-P01–P10 primary sequence is completed, except a safety regression that blocks the active primary acceptance.

| ID | Observation | Classification | Deferred disposition |
|---|---|---|---|
| U427-S001 | Retired UEDB4-only test scripts leave historical contracts broken (`audit-ue4-missing-verifyimport.php`, `CompactDependencyRebuilder`, package-name repair). | Audit harness / retired test compatibility, **not** proven Epic parsing mismatch | Reconcile under U427-P10 after primary source coverage |
| U427-S002 | V4-era UT4 v511 metadata assertions for missing selected physical provider differ; UT4 has separate clean-master source. | Out-of-scope older UT4 integration | Review under future UT4 source audit, not UE4.27.2 |

Add further findings here with stable U427-S IDs, source paths, production impact, and verification state. Do not expand U427-P01–P10.

## U427-S003 — deferred P04 soft-reference decoding question

During P02 source-defined linker FName index enforcement, some valid UE4.27 assets (including `TutorialTPP_PhysicsAsset.uasset` and `OculusControllerMaterial.uasset`) produced out-of-range integers in UnrealDB's existing soft-package-reference decoder. Import/export NameMap index validation remains strict; the soft-reference reader retains its previous behavior. This is **not yet a confirmed Epic discrepancy**: P04 must independently verify the serialized wire format and offsets using Epic source and native samples. The authentic 1,749-package corpus and 112 retained soft references must remain passing.
