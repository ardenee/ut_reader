# P02 — independent Epic decoded classic tables checkpoint

The pinned Epic `UnrealPackageTool.exe` (`-j PackageInfo -p <asset> --names --imports --exports`) and production `UE5/UnrealPackageReader.php` decoded the **same 140 real UE5 Engine Content assets**. Script `catalog/bin/verify-ue5-583-epic-classic-native-tables.php` checks all name-table strings, import object names/outer indexes/optional flags and export object names/class, super, template and outer indexes, serial sizes/offsets and script serialization offsets as exported by Epic.

2026-10-19 result: **140 packages, 44,840 independently compared fields, zero differences, exit 0**. UE5 version distribution: 1004=109, 1006=4, 1007=1, 1009=1, 1010=1, 1012=1, 1018=23.

P02 remains OPEN: direct Epic comparison of preload/depends semantics, other source-dependent fields, and the S001 `RF_Load` semantic flag issue still require reconciliation. These 44,840 comparisons must not be presented as complete P02 certification.
