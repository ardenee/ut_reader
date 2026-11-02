# UE5.8.3 independent native Zen IoStore name parity — 19 October 2026

**Result: PASS**, production `Uedb5` isolated Satisfactory package names vs compiled Epic `UnrealPak.exe` native `-List` on the original `FactoryGame-WindowsServer.utoc`.

Repeatable driver: `catalog/bin/verify-ue5-583-epic-zen-native-package-names.php` (exit 0, `ok=true`). Connects **read only** to DEV isolated MySQL port 3339; checks datadir identity before querying `ue_files WHERE game_id=2`. Executes `H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPak.exe`, built from Epic UE5.8.3, on the authentic Satisfactory Dedicated Server TOC and parses its native `LogIoStore` listing.

Epic native TOC lists 27,241 entries: **27,240 cook/package paths + one special `<ContainerHeader>`**. After Epic physical cooked paths are translated to logical package mounts (/Game, /Engine, plugin namespaces; Interchange /InterchangeAssets and /Interchange plugin rules), case-normalized and .uasset/.umap extensions removed, exactly **27,240 unique Epic logical package names** match **27,240 UnrealDB indexed Zen package names**, with zero unexpected, missing or duplicate normalized identities in the sets. This is independent Epic-generated output compared against retained authentic UnrealDB V5/SQL identities, not two readers using the same parser.

Earlier unnormalized comparison overlap of 26,413, and partial plugin normalization of 27,135, were solely physical path, case and plugin-mount differences. Those are resolved by explicit Epic physical-to-logical mapping, not database modifications. The final verifier is the authoritative comparison.

**Scope:** Confirms this genuine Satisfactory Zen container's complete package name identity *set*. Does not prove all chunk offsets, per-package decompression, cooked header field parity, import/export semantics, dependencies, native runtime loading, alternative containers, encrypted layouts or all 16 application workflow end-to-end acceptances. Full source/reachability and other Zen semantic differentials remain open; `RECOVERY.json.scope_complete=false` and overall compliance still unverified. No PRD/live Satisfactory import.
