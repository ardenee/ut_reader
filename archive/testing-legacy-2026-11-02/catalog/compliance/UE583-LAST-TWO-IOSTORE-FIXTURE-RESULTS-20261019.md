# UE5.8.3 — Remaining two IoStore fixture gates accepted (19 October 2026)

Both drivers run against dedicated DEV-only MySQL 127.0.0.1:3339/uedb5_isolated_integration. PRD MySQL and the Satisfactory production catalog are untouched.

## Missing member restored from retained source: PASS

Driver: `catalog/bin/verify-iostore-missing-member-repair-standalone.php`, exit 0, `ok=true`.

Uses separate restored Satisfactory container ID 5 belonging to disposable game 9002, not the 27,240 published package dataset in game 2/container 4. Indexes and publishes its first package using the normal IoStore services if needed, under the valid active isolated UE5 profile, selects the resulting stable member ID (observed file ID 27241), temporarily renames **only the disposable member's** `.zenpkg` file, then calls `CatalogIoStoreMemberReimportService::reimport`. It verifies rebuilt physical source hashes and stable file ID, restores the test game's prior profile, and removes the saved file only after successful repair. Count of the original game-2 published files remains 27,240.

This confirms missing-derived-file recovery from the unchanged retained verified TOC/UCAS family. Reimport invalidates the disposable member's dependency Pass 2 as expected; it does **not** alter the full dataset's previously verified 27,240 Pass 2 results.

## Actual PHP HTTP multipart staging and durable import: PASS

The local test server on DEV used `D:/php8.5/php.exe -S 127.0.0.1:18765 -t C:/Temp C:/Temp/ue583-http-upload-local-router.php`, with route body invoking `CatalogIoStoreUploadStager::stage($_FILES['toc'],$_FILES['data'])`; it was stopped afterward. A real `curl.exe -F` POST submitted both `global.utoc` (2,982 bytes) and `global.ucas` (3,540,752 bytes) from the **DEV local** Satisfactory Dedicated Server. The PHP upload stager checked genuine `is_uploaded_file` semantics, retained the ordered complete family and verified the TOC hash `0f72c200169b09519ac727d0149ef4f3c35d6234f1326c7efe5ee0bcf04095e6` with partition_count=1 and staged bytes=3,543,734.

Driver: `catalog/bin/verify-iostore-multipart-job-standalone.php`, exit 0, `ok=true`. The isolated durable worker completed job ID 11 (one attempt, no error), retained the correct container (ID 6; zero members as expected for `global.utoc`), and enqueued name-index job 12 and publish job 13. The HTTP fixture is temporary and not a production upload.

## Evidence boundary

These **two fixture acceptance tests are closed** together with the earlier three corrected tests recorded in `UE583-IOSTORE-ACCEPTANCE-FIXTURES-20261019.md`. This does not certify all 16 Epic-to-UnrealDB production workflow branches or alternative UE5 formats. `RECOVERY.json.scope_complete` remains false; no Satisfactory production import.
