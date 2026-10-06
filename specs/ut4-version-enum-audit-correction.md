# UT4 UE4 version-enum audit correction

## Purpose

This document records and supersedes the incorrect 4G conclusion that UT4 clean-master ended at UE4 package version 510 and that explicit v511 required a separate structural-only source policy.

Those conclusions were caused by an audit-tool error, not by Epic source ambiguity.

## Root cause

The audit derived `EUnrealEngineObjectUE4Version` values by counting only enum identifiers beginning with `VER_UE4_`.

That is not equivalent to evaluating the C++ enum.

The source contains real enum members outside that naming pattern. Most importantly, UT4 clean-master contains:

`VAR_UE4_ARRAY_PROPERTY_INNER_TAGS`

Despite the `VAR_` typo, it is a real enumerator and increments every later numeric value.

Older UE4 source also contains nonstandard members such as:

`VER_DEBUG_MATERIALSHADER_UNIFORM_EXPRESSIONS`

The prefix-filtered audit omitted these members and shifted later values.

## Correct source result

Counting every enum member gives UT4 clean-master:

- `VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS = 326`
- `VER_UE4_ENGINE_VERSION_OBJECT = 336`
- `VER_UE4_LOAD_FOR_EDITOR_GAME = 365`
- `VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP = 384`
- `VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION = 444`
- `VER_UE4_SERIALIZE_TEXT_IN_PACKAGES = 459`
- `VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT = 485`
- `VER_UE4_NAME_HASHES_SERIALIZED = 504`
- `VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS = 507`
- `VER_UE4_TemplateIndex_IN_COOKED_EXPORTS = 508`
- `VER_UE4_ADDED_SEARCHABLE_NAMES = 510`
- `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES = 511`
- `VER_UE4_AUTOMATIC_VERSION_PLUS_ONE = 512`
- therefore clean-master latest = **511**.

Final UE4.27.2 similarly has `VER_UE4_AUTOMATIC_VERSION_PLUS_ONE = 523`, therefore final latest = **522**.

## Real-package proof

The faulty one-low reader failed on real UT4 assets exactly at the affected historical boundaries, producing summary/name/preload misalignment errors.

The pre-4G reader, which used the correct numeric values, parsed those same files successfully.

Representative producer metadata recovered from the original packages included:

- package v383: UE4 4.4-era assets;
- package v503: UE4 4.12-era assets;
- package v506: UE4 4.14-era assets.

After restoring the correct gates, all 16 files that blocked the faulty remediation parse with zero issues and build the canonical UT4 v511 source policy.

## Withdrawn conclusions

The following earlier conclusions are invalid and must not be reused:

- clean-master latest is 510;
- unversioned UT4 should assume 510;
- 64-bit export fields begin at 510;
- final UE4.27.2 latest is 521;
- explicit UT4 v511 is outside clean-master;
- v511 requires `ue4-epic-dev-main-ae727f8d-v511-ut4-structural-package`;
- v511 must fail closed for clean-master VerifyImport;
- the one-lower gate set requires source-driven Pass-1 rereads.

The separate v511 structural policy remains recognized only as a migration alias in case any staged row ever contains it.

## Staged-data impact

The faulty 4G remediation had already rewritten part of UT4 staging before the audit error was discovered.

Current observed policy inventory at the correction point:

- `ue4-ut4-clean-master-v510-classic-package`: **24,194 rows** — these were written by the faulty remediation and require original-byte reread under the corrected v511 profile;
- `ue4-4.27.2-release-classic-package`: **40,053 rows** — these retain metadata produced by the pre-4G reader with source-correct numeric gates and do not require byte rereading solely for this correction;
- erroneous separate v511 structural policy: **0 rows**.

The 24,194 bad-policy rows consist of all 24,077 unversioned rows already reparsed under assumed v510 plus 117 explicit-version rows written before the mixed batch stopped.

The repair command is:

`catalog/bin/repair-ut4-v511-pass1.php`

It selects only `ue4-ut4-clean-master-v510-classic-package`, is read-only by default, and requires `--apply` for writes.

The untouched legacy-policy population was re-inventoried at the correction point:

- `ue4-4.27.2-release-classic-package`: **40,053 rows**;
- all **40,053 / 40,053** have catalogue package version 214-511 and licensee 0 (observed range 216-511);
- the earlier diagnostic had already identified exactly **24,077** unversioned UT4 packages, and all 24,077 are now in the faulty v510-policy repair set, so the remaining legacy-policy population is explicit-version metadata produced by the pre-4G reader whose numeric gates were source-correct.

Those untouched rows therefore require **metadata-only** policy refresh, not original-byte reread. The bounded replacement command is:

`catalog/bin/refresh-ut4-v511-source-policy.php`

It accepts only the original legacy policy (plus the withdrawn structural-v511 alias if ever encountered), validates explicit staged summary identity, refuses unversioned rows, writes only the canonical `ue4-ut4-clean-master-v511-classic-package` policy, and transactionally invalidates dependency state for later Pass 2.

The 16 files that exposed the enum-count error were re-run read-only with the restored source gates and all 16 now parse successfully to the canonical v511 policy.

The superseded `reparse-ut4-v510-pass1.php`, `diagnose-ut4-v510-impact.php`, and `refresh-ut4-v510-source-policy.php` commands are hard-disabled.

## Process rule

Future enum audits must parse/count every C++ enumerator, including typoed or nonstandard identifiers. Prefix-based counting is prohibited for source-conformance work.
