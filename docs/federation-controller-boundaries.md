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

## Non-negotiable compatibility

This cleanup does not change federation API endpoint names, signed payload fields, transfer directions, roles, request statuses, pairing secrets, authorization rules, page sizes or UI routes.

Validation:

```text
php catalog/bin/verify-federation-boundaries.php
php catalog/bin/verify-controller-boundaries.php
php catalog/bin/verify-upload-worker-contracts.php
php catalog/bin/verify-architecture-refactor.php
```

The next federation cleanup targets are the Requests/Transfer Queue pages and smaller diagnostic/settings pages that still contain page-local persistence orchestration. Those should be migrated independently so federation protocol behavior remains reviewable.
