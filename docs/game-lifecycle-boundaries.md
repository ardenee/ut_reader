# Game Manager lifecycle boundaries

Game reset and delete operations are owned by focused classes under `catalog/src/Infrastructure/Games`.

## Ownership

- `CatalogGameAdminService` validates Game Admin requests and calls `CatalogGameLifecycleService` directly.
- `CatalogGameLifecycleService` coordinates reset/delete sequencing, PAK/staging cleanup, table maintenance and reset projection reconciliation.
- `CatalogGameManagedFileCleanup` owns verified-file record deletion, package-alias cleanup and managed game storage removal.
- `CatalogGameStorageCleanup` owns filesystem containment, recursive `storage/games/<slug>` removal and explicit staged-file deletion.
- `PdoCatalogGameTableMaintenance` owns table-existence probes and `OPTIMIZE TABLE` execution/result parsing.
- `CatalogGameLifecycleProgress` preserves the historical progress callback shape and normalization.
- `lib/GameManagerLifecycle.php` is compatibility-only. It must not regain SQL transactions, filesystem traversal or optimization logic.

## Preserved behavior

Reset/delete retain the established contracts:

- verified `ue_files` deletion in 100-row batches;
- package-alias cleanup before verified file deletion;
- managed `storage/games/<slug>` removal with storage-root containment checks;
- cleanup only of unverified rows where `game_id IS NULL`, `scan_status='unverified'` and `unverified_queue_game_id` matches the game;
- retained `ue_pak_archives` accounting/removal;
- MySQL `OPTIMIZE TABLE` rowset error/warning handling;
- reset table optimization at 78–96%, zero-state projection reconciliation at 98%, then completion at 100%;
- delete configuration stage at 70–76%, table optimization at 78–99%, then completion at 100%.

## Regression coverage

Run:

```powershell
php catalog/bin/verify-game-lifecycle-boundaries.php
php catalog/bin/audit-legacy-runtime-references.php
php catalog/bin/verify-controller-boundaries.php
php catalog/bin/verify-architecture-refactor.php
php catalog/bin/verify-upload-worker-contracts.php
```

The lifecycle verifier also protects against the historical regression where `GameManagerLifecycle.php` called controller-local `gm_emit()` and `gm_reset_game_files()` after those functions had been removed from `game-manager.php`.
