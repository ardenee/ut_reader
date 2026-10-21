# UT99 v1.400 — Epic package-entry source specification

**Basis: available version 400 C++ source** (do not assume this revision applies to other UE1 releases or third-party forks).

Source root: `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]`

Pinned files:

| File | SHA-256 |
|---|---|
| `Core\Inc\UnObjVer.h` | `E81EC92C03F0F8996D1A347F84F669196EA9135147EA1FE8D0BBC1E79BFFC3BE` |
| `Core\Src\UnLinker.h` | `810B2B4C86482272D00BD0460561F8D09BB8F7E72EF478A67FAA1E53CADCD9A9` |

## Engine-defined boundaries

- `UnObjVer.h:20`: `PACKAGE_FILE_TAG = 0x9E2A83C1`.
- `UnObjVer.h:23`: `PACKAGE_FILE_VERSION = 68`.
- `UnObjVer.h:27`: `PACKAGE_MIN_VERSION = 60`.
- `UnLinker.h:144-205`: `FPackageFileSummary` serializes tag, file version, package flags, name/export/import table counts and offsets. If `FileVersion >= 68`, GUID and generations follow; otherwise heritage count/offset are read and heritage GUIDs accessed by seek.
- `UnLinker.h:327-333`: `ULinkerLoad` opens a file archive through `GFileManager->CreateFileReader`, throwing if opening fails.
- `UnLinker.h:345-360`: set persistent loading archive state; deserialize summary; assign `ArVer = Summary.FileVersion`; copy package flags to the package root.
- `UnLinker.h:362-369`: compare `Summary.Tag` against the **single exact constant** `PACKAGE_FILE_TAG`; on mismatch log BinaryFormat then abort.
- `UnLinker.h:371-376`: if file version is older than 60, ask the user whether to proceed; declining aborts. The given source snippet does not impose an unconditional maximum-version rejection.
- `UnLinker.h:378-418`: prepare tables then deserialize name, import and export maps.
- `UnLinker.h:433-439`: register the linker; verify unless `LOAD_NoVerify`; success follows verification.

## Numbered source execution path — entry and tag

1. Accept caller-provided root, filename and load flags.
2. Open archive through the file manager; fail if opening fails.
3. Check for an existing linker with the same root.
4. Initialize archive version/loading state.
5. Deserialize `FPackageFileSummary`, including the relevant conditional fields.
6. Set archive version and package root flags.
7. Require `Summary.Tag == 0x9E2A83C1`. If false, log and abort.
8. Apply the pre-60 interactive legacy-version decision.
9. Continue with name/import/export maps, verification and success path.

Scope of this document is **source-defined file-entry and tag behavior only**, not full import resolution or properties.

## Distinct UE1 branch warning

The available `L:\Source\Games\Unreal\Unreal [v1.227]\Core\Inc\UnObjVer.h` specifies `PACKAGE_FILE_TAG = 0x9E2A83C1`, `PACKAGE_FILE_VERSION = 69`, `PACKAGE_MIN_VERSION = 60`; `UnLinker.h:97-180` includes branch-specific licensee version splitting and clamped generation-count reading. Its source contains explicit Legend licencee alterations, so those rules must be separately pinned and must not be retroactively attributed to UT99 v1.400.
