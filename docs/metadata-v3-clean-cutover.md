# UnrealDB metadata v3 clean cutover

The migration has one runtime boundary: production stays on v2 until every staged
`.uedb3` verifies. After cutover, normal application code is v3-only and must
never fall back to `.uedb2`.

## 1. Finish offline staging

Keep the existing four `catalog/bin/v3-migration/stage.php` workers running.
Do not add files or run game resyncs while staging is in progress.

Preserve each worker's final JSON. Any worker failure must be resumed from that
worker's own `resume_after_id`.

## 2. Update the source checkout, but do not deploy runtime yet

Run the aggregate source gate:

```powershell
php catalog/bin/verify-v3-migration-ready.php
```

It must return `"ok": true`.

## 3. Stop the catalog workers/import activity and public web runtime

Stop detached workers/import activity and take Apache/public catalog requests
offline for the short cutover window. PHP CLI can remain available.

The cutover command refuses to run while a background job has status `running`.
Do not restart Apache or workers until the runtime code and database registration
have both been switched to v3.

## 4. Verify the complete staged population

From the production application directory:

```powershell
php catalog/bin/v3-migration/cutover.php
```

This is read-only. It verifies every verified catalog file has a structurally
valid, identity-matching `.uedb3`, validates Names/Imports/Exports/Dependencies
counts, and builds a temporary registration set. Any error aborts the cutover.

## 5. Deploy the v3-only runtime

Deploy the current source checkout while workers remain stopped. The runtime has
no normal v2 reader/resolver path.

## 6. Atomically switch database registrations

Only after step 4 is clean and the v3 runtime is deployed:

```powershell
php catalog/bin/v3-migration/cutover.php --apply --confirm-v3-only
```

The command re-verifies the complete population and updates all verified
`ue_file_metadata` registrations to format 3 in one transaction. Any incomplete
registration rolls the transaction back.

## 7. Verify live v3 state

Run:

```powershell
php catalog/bin/verify-compact-only-metadata-runtime.php --database
php catalog/bin/verify-blocked-metadata-storage.php --hash --container
php catalog/bin/verify-v3-migration-ready.php
```

Do not remove `.uedb2` until these are clean.

## 8. Restart Apache/workers and run Full Sync for every game

Use the existing Full Sync workflow for each game. This republishes projections
and dependencies through the v3-only resolver, including package-wide
same-provider object coverage.

A broken/missing `.uedb3` detected by runtime readers queues the globally
deduplicated `compact-metadata-repair:<file_id>` repair. Repair reparses the
authoritative Unreal package, verifies the replacement, and refreshes affected
dependencies. There is no v2 fallback.

## 9. Validate package-superset behavior

After the Full Syncs, a package can be inspected read-only with:

```powershell
php catalog/bin/analyze-v3-package-superset.php --game-id=GAME_ID --package=PACKAGE
```

The result reports complete/partial/non-matching providers and exact missing
object paths.

## 10. Remove the cold v2 files

After the desired rollback window, first dry-run:

```powershell
php catalog/bin/v3-migration/cleanup-v2.php
```

Then:

```powershell
php catalog/bin/v3-migration/cleanup-v2.php --apply --confirm-delete-v2
```

Each active `.uedb3` is verified before its corresponding `.uedb2` is
deleted. The command refuses cleanup if verified DB rows are not all v3.

## 11. Retire migration-only source

Once cleanup is complete and no rollback to v2 is required, remove
`catalog/bin/v3-migration/` from the deployed/runtime source. The normal
application must contain only the production v3 container implementation.
