# UE5.8.3 — Independent Epic UnrealPak v11 differential (19 October 2026)

**Verdict: PASS for tested genuine Satisfactory Dedicated Server PAK v11.** This is a direct Epic UE5.8.3 tool-versus-UnrealDB comparison, not two PHP consumers agreeing.

Source archive: `C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.pak`. Authoritative compiled official Epic tool: `H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPak.exe`, built from pinned UE5.8.3 source. DEV only.

## Two separate independent tests

1. **PAK index parity:** `catalog/bin/verify-ue5-583-epic-unrealpak-index-differential.php` invokes actual Epic `UnrealPak.exe <pak> -List`, parses the official emitted entry names, offsets, stored sizes and compression methods, and compares them against `catalog_pak_parse_index`. Exit 0; `ok=true`; **4,761 Epic names and 4,761 UnrealDB names; 4,761/4,761 matching; no missing names or metadata discrepancies**.
2. **Decoded byte parity:** The same official UnrealPak command with `-Extract C:/Temp/ue583-epic-extracted-20261019` produced 4,761 physical files (the tool reported successful execution). `catalog/bin/verify-ue5-583-epic-unrealpak-content-differential.php` independently decoded every entry via the production `catalog_pak_write_entry_data` into a single reused disposable output and compared each member's exact size and SHA-256 with the corresponding Epic-extracted file. PHP exit 0; `ok=true`; **4,761/4,761 matching files, 212,783,532 decoded bytes, 2,978 uncompressed members and 1,783 Oodle members, zero discrepancies**. Each temporary UnrealDB decoded file was deleted; Epic's reference extraction remains under `C:/Temp` for repeat tests and is not an application archive import.

**Important hash nuance:** UE5 UnrealPak's `-List` SHA-1 on stored/compressed bytes is not interchangeable with a decoded member's SHA-1. Compact directory index metadata in this PAK does not carry equivalent per-member stored hashes; this test compares the actual decoded bytes using SHA-256 instead of reporting a false hash failure.

## Reproduction

Run in DEV PowerShell (the full extraction writes approximately 213 MB and must not target production package storage):

```powershell
$pak = 'C:\Program Files (x86)\Steam\steamapps\common\SatisfactoryDedicatedServer\FactoryGame\Content\Paks\FactoryGame-WindowsServer.pak'
$epic = 'H:\UE5-583-runtime-probe\Engine\Binaries\Win64\UnrealPak.exe'
$extract = 'C:\Temp\ue583-epic-extracted-20261019'
& $epic $pak -Extract $extract
& D:\php8.5\php.exe catalog\bin\verify-ue5-583-epic-unrealpak-index-differential.php
& D:\php8.5\php.exe catalog\bin\verify-ue5-583-epic-unrealpak-content-differential.php
```

Environment overrides `UE583_SATISFACTORY_PAK`, `UNREALDB_EPIC_UNREALPAK`, `UE583_EPIC_EXTRACTED_DIR` supported by the respective PHP drivers.

## Scope boundaries

This **independently closes the 4,761-entry genuine modern PAK v11 default layout** for listing and decoded content, including Oodle. It does not certify encrypted, path-hash-only or unencoded alternate PAK directory layouts, classic UE5 .uasset headers, or Zen/IoStore package metadata against Epic native runtime. Broader Epic source/entrypoint branch inventory and all 16 workflow *acceptance* gates remain open. `RECOVERY.json.scope_complete` must stay `false`. No Satisfactory production import occurred.
