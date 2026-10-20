# UE4.27.2 — Epic package header and loader (source-only)

**Source of truth:** original Epic UE4.27.2 C++ at
`L:/Source/Engine/UE4/UE 4.27.2`, revision
`d94b38ae3446da52224bedd2568c078f828b4039`.
This is an **incomplete** first package-loading/format section, not a
full file-format certification and not a declaration of PHP parity.

## Read in this order

1. [Epic source file inventory, field tables, and loader steps](source-reference.md)
2. [Epic package-loading reference PDF](UE4.27.2-Epic-Source-Package-Format-and-Loader.pdf)
3. [Editable nine-page sequence diagrams](source-reference.drawio):
   `loader`, `summary`, `imports`, `exports`, `tables`, `writers`, `version_correction`, `engine_changelist`, `package_flags`
4. [True/false summary decision graph](summary-decision-graph.md)
5. [Editable decision graph](summary-decision-graph.drawio)

The diagrams can be opened in https://app.diagrams.net/ or diagrams.net
desktop. Use the `.drawio` format to preserve editable decision nodes,
source-location labels and connectors. Mermaid variants `flow-*.mmd` and
`summary-decision-graph.mmd` are also kept for source review.

**Interpretation rule:** sequence diagrams describe the order in which
statements appear in Epic. They are not exhaustive control-flow graphs.
The separate true/false graph is a **selected** subset of the original
header branches. Only the original C++ determines the full path.

## Rebuild from original Epic source

From the DEV repository root:

```powershell
powershell -ExecutionPolicy Bypass -File catalog/bin/build-epic-ue427-package-handbook.ps1
```

The builders refuse changed Epic source hashes, missing C++ anchors,
unknown decision destinations, or a mismatch between diagram tab count
and the versioned manifest. The PDF is regenerated from Epic-derived HTML
using the installed Calibre converter. The reference never reads PHP.

## Original Epic UE4 version enumeration

The source-only `epic-version-enum.json` contains all 311 entries of
`EUnrealEngineObjectUE4Version`, derived directly from the pinned Epic
`Core/Public/UObject/ObjectVersion.h`, including its explicit assignments.
This prevents copying estimated version numbers into later reference sections.

The related production PHP constants are generated separately using
`py -3 catalog/bin/sync-ue427-epic-version-gates.py --write` on DEV.
Without `--write`, that tool checks the source-derived PHP trait without
modifying it.

## Production comparison (separate)

`catalog/compliance/UE427-LOADER-EPIC-STEP-MAPPING.csv` contains **250**
source-indexed items as of the initial generation. Each is
`NOT_CHECKED` until inspected against actual UnrealDB production PHP; individual implemented branches are marked separately and do not imply whole-path acceptance.
It lives outside this folder to keep this reference free of UnrealDB
assumptions. Enter both the equivalent code location and its real caller
when mapping; do not mark complete from a test assertion alone.

## Deliberately unexpanded

The original source functions, runtime state, per-class object data,
property layout, bytecode, bulk sidecars, IoStore and cooked/text archive
branches need their own source traces. Do not interpret this section as
covering those formats.

The older `UT_Package_File_Format.pdf` is an example of document
presentation only and was **not** consulted for this format reference.
