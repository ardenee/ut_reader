# Federation controller boundaries

This phase continues the behavior-preserving modular-monolith cleanup after the Upload Bucket, worker-pool and legacy-controller refactors.

## Connections

`catalog/federation/connections.php` is now a rendering/request controller. Pairing and persistence actions are owned by:

- `CatalogFederationConnectionActions`
- `CatalogFederationConnectionQuery`

The action service preserves the existing parent/child protocol and state transitions, including parent join submit/status/cancel, automatic claim, child approval/denial, peer enable/edit/remove/test/refresh and leaving Parent mode.

The page no longer owns HTTPS federation calls, the child-approval transaction or direct join-request persistence reads.

## Inventories

`catalog/federation/inventories.php` keeps keyset pagination and rendering. Network/mutation behavior is owned by `CatalogFederationInventoryActions` while list/count reads continue through `PdoFederationInventoryListQuery`.

The extracted action service preserves:

- bidirectional inventory refresh behavior;
- Parent-selected child-file transfer queueing;
- duplicate active-transfer suppression;
- configured download speed/delay settings;
- Child missing-dependency request validation against the current cursor page;
- Parent request-status checks;
- Parent base-game policy refresh/caching;
- the existing policy-change rejection redirect;
- signed request-submit payloads and request-page redirect.

## Requests

`catalog/federation/requests.php` now delegates parent decisions, child cancellation, approved-download queueing and signed Parent request-status reads to `CatalogFederationRequestService`.

The parent/child rendering partials reuse that same service. In particular, the established parent detail behavior that refreshes package matches before displaying a request remains intact, but the side effect is no longer implemented in the presentation partial.

The service preserves:

- selected and approve-all/deny-all actions;
- parent package-availability checks;
- parent base-game-policy exclusion decisions;
- request-header status recalculation;
- signed Child cancellation calls;
- signed Child request-status calls and policy caching;
- queueing only approved dependencies that are still required locally;
- the existing approval/waiting/denial status messages.

Read-only request/transfer history pagination still uses `PdoFederationHistoryPageQuery`; moving those SQL specifications into dedicated read models is a later query-model cleanup rather than part of the lifecycle mutation change.

## Transfer Queue

`catalog/federation/queue.php` now delegates cancel/retry mutation, tab-to-status mapping and policy-visible summary counts to `CatalogFederationTransferService`.

The service preserves the exact established state machine:

- Active = `queued` + `running`;
- Waiting for import = `downloaded`;
- Failed = `failed`;
- Completed = `imported`;
- Cancelled = `cancelled`;
- only queued/failed jobs may be cancelled from this page;
- only failed/cancelled jobs may be retried;
- retry clears downloaded identity/path, timing, progress and error fields exactly as before.

The paginated transfer-history SELECT remains read-only presentation/query specification for now.

## Non-negotiable compatibility

This cleanup does not change federation API endpoint names, signed payload fields, transfer directions, roles, request statuses, pairing secrets, authorization rules, page sizes or UI routes.

Validation:

```text
php catalog/bin/verify-federation-boundaries.php
php catalog/bin/verify-controller-boundaries.php
php catalog/bin/verify-upload-worker-contracts.php
php catalog/bin/verify-architecture-refactor.php
```

Remaining federation cleanup is primarily query-model and diagnostics/settings debt rather than core connection/request/transfer lifecycle orchestration. Those smaller areas should continue to be migrated independently so federation protocol behavior remains reviewable.
