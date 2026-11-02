#!/usr/bin/env python3
"""Extract Epic's UE4.27.2 numeric version enum and check production reader constants.

This is source-derived, not a PHP simulation of Unreal parsing. No guessed enum
numbers or manually enumerated expected numeric values are used.
"""
import hashlib
import json
from pathlib import Path
import re
import sys

REPO=Path(__file__).resolve().parents[2]
EPIC=Path("L:/Source/Engine/UE4/UE 4.27.2/Engine/Source/Runtime/Core/Public/UObject/ObjectVersion.h")
SOURCE_DIGEST="378C49E3AF3542C87C95D7259B81FCB77A98077D438CCCFA227B2F90870A000A"
OUTPUT=REPO/"specs"/"epic-source"/"ue4-4.27.2"/"package-loading"/"epic-version-enum.json"
PHP=REPO/"UE4"/"UnrealPackageReader.php"
if not EPIC.is_file():
    raise SystemExit(f"ERROR: pinned Epic source not available: {EPIC}")
raw=EPIC.read_bytes()
actual=hashlib.sha256(raw).hexdigest().upper()
if actual!=SOURCE_DIGEST:
    raise SystemExit(f"ERROR: original Epic source changed: expected {SOURCE_DIGEST}, got {actual}")
source=raw.decode("utf-8-sig")
match=re.search(r"enum\s+EUnrealEngineObjectUE4Version\s*\{(.*?)\n\s*\};",source,re.S)
if match is None:
    raise SystemExit("Epic UE4 version enum structure changed")
members={}
counter=None
begin_line=source[:match.start(1)].count("\n")+1
# Epic enum contains a single explicit first value and a symbolic expression
# for the trailing current version. Reject any other expression rather than guess.
for relative,raw_line in enumerate(match.group(1).splitlines(),start=0):
    code=raw_line.partition("//")[0].strip().rstrip(",").strip()
    if not code: continue
    item=re.fullmatch(r"([A-Za-z_][A-Za-z_0-9]*)(?:\s*=\s*(.*))?",code)
    if item is None: raise SystemExit(f"Unrecognized Epic enum line: {raw_line}")
    name,rhs=item.groups()
    if rhs is None:
        if counter is None: raise SystemExit(f"First Epic enum value not explicit: {name}")
        counter+=1
    elif re.fullmatch(r"[0-9]+",rhs):
        counter=int(rhs)
    else:
        symbolic=re.fullmatch(r"([A-Za-z_][A-Za-z_0-9]*)\s*-\s*([0-9]+)",rhs)
        if symbolic is None or symbolic.group(1) not in members:
            raise SystemExit(f"Unrecognized Epic enum expression {name}={rhs}")
        counter=members[symbolic.group(1)]["value"]-int(symbolic.group(2))
    if name in members: raise SystemExit(f"Duplicate Epic enum {name}")
    members[name]={"value":counter,"line":begin_line+relative}
# Verify last-version contract from original Epic enum.
assert members["VER_UE4_OLDEST_LOADABLE_PACKAGE"]["value"]==214
assert members["VER_UE4_AUTOMATIC_VERSION"]["value"]==members["VER_UE4_AUTOMATIC_VERSION_PLUS_ONE"]["value"]-1
php=PHP.read_text(encoding="utf-8")
aliases={
    "VER_SERIALIZE_TEXT_IN_PACKAGES":"VER_UE4_SERIALIZE_TEXT_IN_PACKAGES",
    "VER_ADD_STRING_ASSET_REFERENCES_MAP":"VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP",
    "VER_ADDED_SEARCHABLE_NAMES":"VER_UE4_ADDED_SEARCHABLE_NAMES",
    "VER_ENGINE_VERSION_OBJECT":"VER_UE4_ENGINE_VERSION_OBJECT",
    "VER_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION":"VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION",
    "VER_WORLD_LEVEL_INFO":"VER_UE4_WORLD_LEVEL_INFO",
    "VER_WORLD_LEVEL_INFO_UPDATED":"VER_UE4_WORLD_LEVEL_INFO_UPDATED",
    "VER_WORLD_LEVEL_INFO_LOD_LIST":"VER_UE4_WORLD_LEVEL_INFO_LOD_LIST",
    "VER_WORLD_LEVEL_INFO_ZORDER":"VER_UE4_WORLD_LEVEL_INFO_ZORDER",
    "VER_WORLD_LAYER_ENABLE_DISTANCE_STREAMING":"VER_UE4_WORLD_LAYER_ENABLE_DISTANCE_STREAMING",
    "VER_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE":"VER_UE4_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE",
    "VER_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS":"VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS",
    "VER_ASSETREGISTRY_DEPENDENCYFLAGS":"VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS",
    "VER_CORRECT_LICENSEE_FLAG":"VER_UE4_CORRECT_LICENSEE_FLAG",
    "VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS":"VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS",
    "VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS":"VER_UE4_TemplateIndex_IN_COOKED_EXPORTS",
    "VER_LOAD_FOR_EDITOR_GAME":"VER_UE4_LOAD_FOR_EDITOR_GAME",
    "VER_COOKED_ASSETS_IN_EDITOR_SUPPORT":"VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT",
    "VER_64BIT_EXPORTMAP_SERIALSIZES":"VER_UE4_64BIT_EXPORTMAP_SERIALSIZES",
    "VER_NAME_HASHES_SERIALIZED":"VER_UE4_NAME_HASHES_SERIALIZED",
    "VER_ADDED_SOFT_OBJECT_PATH":"VER_UE4_ADDED_SOFT_OBJECT_PATH",
    "VER_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID":"VER_UE4_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID",
    "VER_ADDED_PACKAGE_OWNER":"VER_UE4_ADDED_PACKAGE_OWNER",
    "VER_NON_OUTER_PACKAGE_IMPORT":"VER_UE4_NON_OUTER_PACKAGE_IMPORT",
    "VER_OLDEST_LOADABLE_PACKAGE":"VER_UE4_OLDEST_LOADABLE_PACKAGE",
    "DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION":"VER_UE4_AUTOMATIC_VERSION"
}
# No production version number is allowed to be typed into the reader.
# The generated trait is built from original Epic enum entries and member names.
manual=re.findall(r"private\s+const\s+(VER_[A-Z_0-9]+|DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION)\s*=",php)
if manual:
    raise SystemExit(f"Manual reader version constants remain: {manual}")
GENERATED=REPO/"UE4"/"EpicUE427VersionConstants.php"
generated_lines=["<?php",
    "declare(strict_types=1);",
    "",
    "/**",
    " * AUTO-GENERATED FROM PINNED EPIC UE4.27.2 ObjectVersion.h.",
    " * Original C++ is authoritative; do not hand-edit these values.",
    " * Epic SHA-256: "+actual,
    " */",
    "trait EpicUE427VersionConstants",
    "{"]
for php_name,epic_name in sorted(aliases.items()):
    if epic_name not in members:
        raise SystemExit(f"Source enum member absent: {epic_name}")
    generated_lines.append(
        f"    private const {php_name} = {members[epic_name]['value']}; // {epic_name}")
# Extract *original Epic* ObjectMacros.h symbol assignments and the RF_Load
# expression, not a copied constant / guessed aggregate bitmask.
object_macros=Path("L:/Source/Engine/UE4/UE 4.27.2/Engine/Source/Runtime/CoreUObject/Public/UObject/ObjectMacros.h")
object_macros_digest="2BCF3EB5BA3013602FB5C02325DCFEF9B427779D3391A32D0F90D5D7A12FE20F"
raw_object_macros=object_macros.read_bytes()
if hashlib.sha256(raw_object_macros).hexdigest().upper()!=object_macros_digest:
    raise SystemExit("Pinned Epic ObjectMacros.h is absent or changed")
object_macros_source=raw_object_macros.decode("utf-8-sig")
object_flags={name:int(value,16) for name,value in re.findall(
    r"^\s*(RF_[A-Za-z0-9_]+)\s*=\s*(0x[0-9a-fA-F]+)\s*,",
    object_macros_source,re.M)}
package_flags={name:int(value,16) for name,value in re.findall(
    r"^\s*(PKG_[A-Za-z0-9_]+)\s*=\s*(0x[0-9a-fA-F]+)\s*,",
    object_macros_source,re.M)}
rf_match=re.search(r"^#define\s+RF_Load\s+\(\(EObjectFlags\)\(([^)]+)\)\)",
    object_macros_source,re.M)
if rf_match is None: raise SystemExit("Original Epic RF_Load macro missing")
rf_names=[item.strip() for item in rf_match.group(1).split("|")]
if not rf_names or any(name not in object_flags for name in rf_names):
    raise SystemExit("Unsupported Epic RF_Load component; do not infer missing flags")
rf_load=0
for name in rf_names:rf_load|=object_flags[name]
if "PKG_NewlyCreated" not in package_flags or "PKG_FilterEditorOnly" not in package_flags:
    raise SystemExit("Required original Epic package flag definitions missing")
source_flag_constants={
    "EPIC_RF_LOAD_MASK":rf_load,
    "EPIC_PKG_NEWLY_CREATED":package_flags["PKG_NewlyCreated"],
    "EPIC_PKG_FILTER_EDITOR_ONLY":package_flags["PKG_FilterEditorOnly"],
}
for name,value in source_flag_constants.items():
    generated_lines.append(f"    private const {name} = 0x{value:08X}; // ObjectMacros.h")
generated_lines.extend(["}", ""])
expected_generated="\n".join(generated_lines)
if "--write" in sys.argv:
    GENERATED.write_text(expected_generated,encoding="utf-8")
elif not GENERATED.exists() or GENERATED.read_text(encoding="utf-8")!=expected_generated:
    raise SystemExit("Generated UE4 Epic version trait absent/stale. Regenerate with --write on DEV.")
php_consts={name:int(value) for name,value in re.findall(
    r"private\s+const\s+(VER_[A-Z_0-9]+|DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION)\s*=\s*([0-9]+)\s*;",expected_generated
)}
if set(php_consts)!=set(aliases):
    raise SystemExit("Generated PHP version constant coverage incomplete.")
result=[]
differences=[]
for php_name in sorted(php_consts):
    enum_name=aliases[php_name]
    if enum_name not in members:
        raise SystemExit(f"Epic enum member {enum_name} does not exist; refusing inference")
    expected=members[enum_name]["value"]
    row={"php_constant":php_name,"epic_enum":enum_name,
         "epic_value":expected,"epic_source_line":members[enum_name]["line"]}
    result.append(row)
    if expected!=php_consts[php_name]:
        differences.append((php_name,php_consts[php_name],expected))
reference={"engine":"UE4.27.2","epic_source_file":"Engine/Source/Runtime/Core/Public/UObject/ObjectVersion.h",
           "epic_sha256":actual,
           "epic_enum":"EUnrealEngineObjectUE4Version",
           "members":members}
OUTPUT.write_text(json.dumps(reference,indent=2)+"\n",encoding="utf-8")
print(f"Epic source enum: {len(members)} entries, source SHA256 {actual}")
print(f"PHP source-checked constants: {len(result)}")
for row in result: print(f"{row['php_constant']:53s} Epic {row['epic_value']:4d} ({row['epic_enum']})")
if differences:
    for name,actual_val,expected in differences:
        print(f"ERROR: {name} PHP={actual_val} EPIC={expected}",file=sys.stderr)
    sys.exit(1)
print("PASS: EVERY mapped UE4 production PHP version constant equals original Epic C++")
