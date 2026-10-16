# Original B006 — UE5 mounted file-store policy

**Operation:** `UE5-583-FILE-STORE-001`. Pinned Epic UE5.8.3 `FilePackageStore.cpp`, SHA256 `dfdd4e226b3a41f36476ddd93225aa34284ea63943f1752216e0f2a25c2a2418`.

## Source branch to implementation mapping

- **Mount order (lines 447–459):** Epic records `Order` and monotonic `NextSequence++`, then stable-sorts by descending order and sequence. UnrealDB requires explicitly evidenced numbers and orders mounts identically.
- **Physical entries (662–828):** Epic builds `FPackageIdMap` from sorted mounted `PackageIds` and store entries. UnrealDB uses only independently evidenced `package_entries` and first-mounted file IDs. Earlier D008 corrected the missing file-backend entries. An on-disk TOC does not confer live runtime authority.
- **Localization (788–796):** `LocalizedPackages.FindOrAdd` fills an entry while its stored name is `NAME_None`. Higher valid names win, but lower mounts can replace explicit `None`.
- **Redirects (798–808):** `RedirectsPackageMap.FindOrAdd` fills source name and target while its name is `NAME_None`. Explicit redirects are checked before localized rerouting.
- **Backend/status (332–385 and `PackageStore.cpp:565–593`):** File backend entry exists → `Ok`, absent → `Missing`; global backend priority is separate. Localization depends on runtime `GIsEditor`, active culture and an installed target.

## Source differences and correction

**D016:** UnrealDB's unconditional first-wins redirect/localization sets blocked valid lower mounts when the earlier entry had explicit `NAME_None`. Two source-shaped regression checks failed before commit `7a8708fb95f66b900e09ea70a89dc216bc96860a` and pass afterwards. Missing source-name *evidence* remains unknown, not automatically `NAME_None`; valid upper-mount names and physical file-ID precedence remain first-wins.

**D008:** Previously discarded physical file-backend entry mappings were already corrected. The isolated Satisfactory header/SQL test evidenced two actual package IDs, not a full mounted-game session.

## Executable acceptance

`verify-uedb5-b006-mounted-source.php` checks the pinned C++ hash, source branches, independent mount permutations, `NAME_None`, malformed inputs and all direct production builder callers. `verify-uedb5-b006-operation-acceptance.php` combines six drivers with 12 mandatory assertions: mounted ordering, effective cross-backend priority, game context, backend arbitration and actual PDO physical-provider selection. The formal verifier must rerun these and match implementation fingerprints to retain B006's accepted state.

**Boundary:** This certifies source-defined *mount-policy handling using explicitly observed data*. It does not claim that a static archive captures an entire running engine's mounted container set, exact mount order, culture, editor flags or every non-file backend. Those facts require external runtime evidence; broader identity resolution remains original B008/global scope work.
