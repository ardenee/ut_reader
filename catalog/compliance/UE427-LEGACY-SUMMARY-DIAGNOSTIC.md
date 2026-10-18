# UE4.27.2 legacy summary boundary — diagnostic inspector

This is a **separate, bounded UE4 source comparison**, not a new UE5 B operation and not a claim that overall UE1–UE5 compliance is established. The original UE5 B015-1 remains partial only because representative Verse compiler/UHT provenance and VerseVM ScriptCells runtime cannot be demonstrated with the available tools. The unavailable restricted AutoRTFM toolchain is not being pursued further.

**Epic source:** `C:\Users\arden\source\repos\UE4\Engine\Source\Runtime\CoreUObject\Private\UObject\PackageFileSummary.cpp`, SHA-256 `8FF60DD29D5A0E50EB1222DA0D9C5DF71E088A1F2641E8512E6F6CE05CBA4146`, lines 102–117. `CurrentLegacyFileVersion=-7`; when loading a negative `LegacyFileVersion` below that version Epic resets the effective versions to zero and returns **before reading the remaining summary**. This is a format-layout guard, not a manually selected game-profile min/max file-version gate.

**UnrealDB difference identified and corrected:** `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php::inspectUe4` previously continued interpreting the following fields with the known layout when given `-8` or `-9`; the diagnostic output could misrepresent the summary as parseable. It now returns an explicit unsupported-legacy-layout diagnostic in that **read-only inspector only**. This does not affect archive import, upload, resynchronization, backup restoration, primary package reader dispatch or stored version details; no artificial UE4 version range is introduced.

**Executable verification:** `catalog/bin/verify-ue427-legacy-summary-inspector.php` creates three isolated, synthetic package-summary byte sequences with authentic UE package magic: markers `-8` and `-9` must be rejected with this precise diagnostic, while `-7` must remain parseable by the inspector. Three of three checks pass on DEV. These synthetic fixtures are not an authentic cooked UE4 package, do not assert full summary semantics, and cannot substitute for later complete UE4 call-path/source audit.

This is a **diagnostic discrepancy fix**, not closure of the broader UE4 source audit or the unresolved UE5 Verse branch.
