# Public contribution upload

The public contribution uploader is intentionally separate from administrator Upload Bucket ingress.

## Goals

- reject unsupported files in the browser before network transfer;
- avoid duplicate uploads without one HTTP/SQL lookup per file;
- keep the browser responsive while hashing large folders;
- upload only one accepted file at a time;
- isolate anonymous bytes from trusted/admin staging;
- return the browser to the next file as soon as transfer completes;
- perform authoritative validation, parsing and unverified indexing in background jobs;
- leave verified catalog promotion under administrator control.

## Client workflow

The public page is \`catalog/public-upload.php\`.

The browser:

1. walks selected files/folders lazily;
2. checks extension and the existing Unreal header/magic rules;
3. calculates MD5 and SHA-1 in the existing Web Worker for normal package files;
4. reads legacy UE1/UE2 package GUID only where the repository's package-summary layout is deterministic;
5. sends at most 100 checked identities to \`api/v1/public-upload-preflight.php\`;
6. receives \`upload\`, \`skip\` or \`reject\` per client ID;
7. for selected ZIP/RAR/7z source archives, mounts the browser File through WORKERFS, lists members without uploading the archive, and extracts/checks one eligible Unreal member at a time;
8. transfers only accepted files, one file at a time, through ordered chunks; chunks may use gzip transport compression when it saves at least 10%, while reservation identity remains the original package bytes;
9. terminates each archive-member worker immediately after skip/upload so its MEMFS/WASM heap can be reclaimed;
10. supports Stop and immediately cancels the current/not-yet-started reservations from that accepted batch.

The log is bounded so very large folder submissions do not create an ever-growing DOM.

## Batched duplicate checks

\`CatalogPublicUploadBatchPreflight\` does not call the normal per-file \`CatalogUploadDuplicateDetector::inspect()\` loop.

For a maximum 100-file manifest it performs set-based queries using the existing global MD5, SHA-1 and GUID indexes:

- one MD5 candidate query;
- one active public-reservation identity query;
- one grouped GUID query;
- one multi-row reservation insert.

An exact duplicate is size + MD5 + SHA-1. Before a matching database row suppresses upload, its controlled physical file is checked for presence and expected size. Stale/missing storage metadata therefore does not prevent a contributor from supplying replacement bytes.

GUID is advisory. A GUID match with different physical hashes is allowed and flagged for administrator review.

## Reservation and race control

Eligible files receive opaque 64-hex upload tokens.

\`active_identity_key\` is a unique SHA-256 key derived from MD5 + SHA-1 + size. It prevents contributors on different IP addresses from racing the same exact physical file through preflight simultaneously.

Per-IP advisory locking serializes rate/reservation accounting.

Reservations are bounded by:

- enabled/disabled setting;
- maximum file size;
- files per hour / IP;
- bytes per hour / IP;
- maximum outstanding reservations / IP;
- one active transferring file / IP;
- reservation lifetime;
- minimum free-space reserve.

These settings are managed on \`catalog/program-settings.php\`.

## Secondary public quarantine

Anonymous bytes are written only beneath:

\`storage/public-uploads/incoming/\`

Chunks are sequential, token/IP-bound and checked against the declared byte count. Before each chunk is accepted the storage free-space reserve is checked again.

The completed HTTP request does not parse dependencies or promote a file. It atomically publishes the quarantine file and queues \`catalog.process_public_upload\`.

Public transfer chunks may be sent as independent gzip streams when browser-side
compression saves at least 10%. The server expands each chunk while holding the
ordered staging lock, counts decoded bytes against the reservation, rolls the
physical file back to the previous offset on any decode/write failure, and keeps
the final quarantine file byte-for-byte identical to the original extracted
Unreal member. Client MD5/SHA-1 and authoritative server hashing therefore refer
to original package bytes, never the transport envelope.

## Background validation

\`CatalogPublicUploadJobHandler\`:

1. reopens the quarantine source;
2. decompresses supported UZ/UZ2/UZ3 redirects;
3. verifies Unreal package magic;
4. calculates authoritative server MD5 and SHA-1;
5. compares client hashes for normal package uploads;
6. performs a final exact duplicate lookup;
7. stages the validated package through the established unverified-file writer;
8. records the authoritative package GUID;
9. queues exact unverified game/dependency evidence as separate background work.

The public contributor cannot assign a game, promote, verify or import a file into the main catalog.

A worker retry can recover the narrow crash window where the unverified writer already moved/indexed the package but the public-upload ledger update did not complete. Recovery uses the authoritative server MD5/SHA-1.

## Public formats

The anonymous surface accepts:

- active game-profile Unreal package extensions;
- \`.uz\`;
- \`.uz2\`;
- \`.uz3\`;
- ZIP, RAR and 7z as **local source archives only**.

The ZIP/RAR/7z container itself is never reserved or uploaded. The browser lists
the archive through a dedicated 7-Zip WASM worker using WORKERFS, ignores
non-upload members without extraction, and extracts only one eligible Unreal
member at a time. Each extracted member must pass the same header/hash preflight
as a directly selected package before any bytes cross the network.

UMOD-family archives and PAK containers remain excluded from the anonymous
surface. Legacy \`.uz\` FCodec redirects are decoded in the browser before
preflight so existing package bytes can still be skipped without upload. Encrypted/link archive members are rejected. Archive-member
size is bounded by the normal public file limit even when the source archive is
larger.

Install the pinned archive decoder with
\`php catalog/bin/install-browser-archive-decoder.php\`.

## Cleanup

Expired reservations release their active identity key. Abandoned partial/final quarantine files are removed by bounded \`catalog.prune_public_uploads\` jobs.

A completed quarantine upload whose background job could not be secured also expires after its reservation lifetime, preventing an orphan from holding an identity lock indefinitely.

## Landing page

The root landing page links directly to the contribution uploader and reports cached verified storage usage/file count per game using \`ue_game_catalog_stats\`.

The project-support section explains that the current locally hosted installation has limited redundancy. The project is not asking for money at this time; spare hard drives, including used drives that their owners already have and consider reliable enough for service, are the useful priority for increasing capacity and redundancy. The landing page links to the public feedback form for anyone who would like to offer a drive.

## Verification

After migrations:

\`\`\`powershell
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/install-browser-archive-decoder.php
php catalog/bin/verify-public-upload-contract.php
\`\`\`

The public-upload contract checks the 100-file boundary, set-based duplicate preflight, physical-presence rule, anonymous authorization boundary, sequential transport, Stop cancellation, disk reserve, idempotent completion, worker recovery, unverified staging, cleanup, menu/landing changes and PHP syntax.
