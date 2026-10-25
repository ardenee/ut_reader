# UE1 O06 — live working checkpoint

**Generated automatically after every DEV test STARTED/PASS/FAIL.** This is operational status, not evidence of full Epic parity.

- **Contract:** `g5-t99-audio-native-loaded-malformed-consolidated`
- **Stage:** T99-Sound-Music-native-loaded-and-malformed-specific-criteria-CLOSED-PASS66-of66-5of7-each-inherited-UObject-still-OPEN
- **Last completed action:** Compiled original UT99 v432 Core.dll+Engine.dll with true native LoadPackage+StaticLoadObject under DEV: nine real Sound at v61+v66 and two real Music at v61+v64. Original archives SHA-pinned and source native buffers verified 1,216,472/1,216,472 bytes exact (424068 Sound, 792404 Music); FileType and OriginalSize exact; nine actual Sound WAV Duration IEEE754 bits independently match Epic v400 FSoundData::GetPeriod. Five distinct DEV-only original-derived native malformed cases: Music zero-count loads with zero Data/OriginalSize despite skipped payload (734772 physical bytes skipped due compact count length change), Music bad FileType and backward lazy seek fail, Sound bad FileType fails, Sound zero WAV native Engine.dll access violation in FWaveModInfo::ReadWaveInfo accessing WavData(0), PHP now fails closed instead of claiming native successful load. Production NativeObjectReader audio TLazyArray permits forward skip per Epic source, retaining hard invalid bounds; original 11 all unaffected. One focused PASS66/66 journal 20261025T112715174Z-6b534c85; source hashes, DLL/executable native code, original file hashes, negative fixture hashes/CDB and native stdout pinned by UE1-O06-G5-AUDIO-NATIVE-LOADED-AND-MALFORMED-ACCEPTANCE.json. Existing v1.200 C98 and v227 C227 source-family branches unchanged; all previous source scopes reconciled True with no previous native engine test replays. Sound and Music each 5/7 G5 method gates VERIFIED: method-specific native values and scoped malformed-input are CLOSED. Remaining inherited UObject and independent method review OPEN; 0FULL 0FAILED 8BLOCKED, DEV only, PRD untouched.
- **Resume at:** STOP all Sound and Music native loaded-value and malformed negative probing for same source/hash. Advance only shared T99:UObject::Serialize unmet inherited and version-variant full gates, then independent G5 method signoff for Sound and Music based on current SHA-pinned 66/66 acceptance. Do not rerun 11 originals/1.216MB Sound+Music native oracle or 5 negatives, do not silently mark methods FULL while inherited UObject is BLOCKED. Continue user priority on concrete Epic-source reader parity fixes and true method closure. PRD remains untouched until full method dependency checks.
- **Active changed at:** 2026-10-25T11:34:11+00:00
- **Latest DEV test states:** PASS 140; FAIL 0; STARTED 0
- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.

## Recent test events (append-only evidence retained)

| UTC | Test | State | Duration (ms) | Evidence |
|---|---|---|---:|---|
| 2026-10-25T11:27:15.7443238Z | verify-ue1-o06-g5-audio-loaded-and-malformed-acceptance.php | PASS | 169 | catalog\compliance\evidence\o06\20261025T112715174Z-6b534c85-DEV-verify-ue1-o06-g5-audio-loaded-and-malformed-acceptance.php.txt |
| 2026-10-25T11:27:15.1823565Z | verify-ue1-o06-g5-audio-loaded-and-malformed-acceptance.php | STARTED |  | catalog\compliance\evidence\o06\20261025T112715174Z-6b534c85-DEV-verify-ue1-o06-g5-audio-loaded-and-malformed-acceptance.php.txt |
| 2026-10-25T10:28:16.9403881Z | verify-ue1-o06-g5-native-audio-source-array-decision.php | PASS | 118 | catalog\compliance\evidence\o06\20261025T102816392Z-9fc33d4a-DEV-verify-ue1-o06-g5-native-audio-source-array-decision.php.txt |
| 2026-10-25T10:28:16.3994224Z | verify-ue1-o06-g5-native-audio-source-array-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261025T102816392Z-9fc33d4a-DEV-verify-ue1-o06-g5-native-audio-source-array-decision.php.txt |
| 2026-10-25T09:34:37.1726627Z | verify-ue1-o06-c227-archetype-native-operator-guard.php | PASS | 104 | catalog\compliance\evidence\o06\20261025T093436644Z-43a655ff-DEV-verify-ue1-o06-c227-archetype-native-operator-guard.php.txt |
| 2026-10-25T09:34:36.6526595Z | verify-ue1-o06-c227-archetype-native-operator-guard.php | STARTED |  | catalog\compliance\evidence\o06\20261025T093436644Z-43a655ff-DEV-verify-ue1-o06-c227-archetype-native-operator-guard.php.txt |
| 2026-10-24T23:44:45.2730452Z | verify-ue1-o06-g5-camera-source-reachability-decision.php | PASS | 575 | catalog\compliance\evidence\o06\20261024T234444289Z-915a2fc3-DEV-verify-ue1-o06-g5-camera-source-reachability-decision.php.txt |
| 2026-10-24T23:44:44.2960430Z | verify-ue1-o06-g5-camera-source-reachability-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T234444289Z-915a2fc3-DEV-verify-ue1-o06-g5-camera-source-reachability-decision.php.txt |
| 2026-10-24T23:44:14.1857701Z | verify-ue1-o06-g5-camera-source-reachability-decision.php | FAIL | 563 | catalog\compliance\evidence\o06\20261024T234413226Z-45fe741b-DEV-verify-ue1-o06-g5-camera-source-reachability-decision.php.txt |
| 2026-10-24T23:44:13.2337707Z | verify-ue1-o06-g5-camera-source-reachability-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T234413226Z-45fe741b-DEV-verify-ue1-o06-g5-camera-source-reachability-decision.php.txt |
| 2026-10-24T23:35:20.3961062Z | verify-ue1-o06-c98-v54-real-model-sphere-source-decision.php | PASS | 159 | catalog\compliance\evidence\o06\20261024T233519831Z-c1dde1d0-DEV-verify-ue1-o06-c98-v54-real-model-sphere-source-decision.php.txt |
| 2026-10-24T23:35:19.8380953Z | verify-ue1-o06-c98-v54-real-model-sphere-source-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T233519831Z-c1dde1d0-DEV-verify-ue1-o06-c98-v54-real-model-sphere-source-decision.php.txt |
| 2026-10-24T23:16:58.7057759Z | verify-ue1-o06-c227-genuine-script-source-decision.php | PASS | 222 | catalog\compliance\evidence\o06\20261024T231658066Z-2607bcfb-DEV-verify-ue1-o06-c227-genuine-script-source-decision.php.txt |
| 2026-10-24T23:16:58.0748088Z | verify-ue1-o06-c227-genuine-script-source-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T231658066Z-2607bcfb-DEV-verify-ue1-o06-c227-genuine-script-source-decision.php.txt |
| 2026-10-24T22:49:51.2358405Z | verify-ue1-o06-g5-uobject-v227-source-flag-decision.php | PASS | 188 | catalog\compliance\evidence\o06\20261024T224950639Z-e10583ee-DEV-verify-ue1-o06-g5-uobject-v227-source-flag-decision.php.txt |
| 2026-10-24T22:49:50.6468725Z | verify-ue1-o06-g5-uobject-v227-source-flag-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T224950639Z-e10583ee-DEV-verify-ue1-o06-g5-uobject-v227-source-flag-decision.php.txt |
| 2026-10-24T22:27:51.3003926Z | verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php | PASS | 112 | catalog\compliance\evidence\o06\20261024T222750785Z-7fe50f4a-DEV-verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php.txt |
| 2026-10-24T22:27:50.7923918Z | verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php | STARTED |  | catalog\compliance\evidence\o06\20261024T222750785Z-7fe50f4a-DEV-verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php.txt |

## Recovery procedure

1. Read this checkpoint and `git status --short`.
2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.
3. Resume only the pending contract and source inputs; use the scoped test runner.
4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.

Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), [UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).
