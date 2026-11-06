#!/usr/bin/env python3
"""Source-independent full-field UE2 table differential on five version cohorts.

Consumes existing SHA-256-pinned original-file evidence and retains it unchanged.
Serializes every decoded Name/Import/Export entry as canonical JSON for compact
cryptographic comparison with UnrealDB's PHP reader. Does not import UnrealDB.
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path

from verify_ue2_source_binary_layout_independent import ADDITIONAL, inspect

ROOT = Path(__file__).resolve().parents[2]
PRIOR = ROOT / "tests/evidence/ue2/ue2-binary-format-15-originals-and-negative-probes-20261106.json"
DEST = ROOT / "tests/evidence/ue2/ue2-original-version-cohorts-full-field-differential-20261106.json"

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--prd-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, default=DEST)
    args = parser.parse_args()
    prior = json.loads(PRIOR.read_text(encoding="utf-8-sig"))
    expected = {int(r["id"]): r for r in prior["new_cases"]}
    if len(expected) != 5:
        raise ValueError("Expected five pinned original cases")
    out = {
        "schema": "ue2-native-source-wire-vs-actual-reader-all-fields-v1",
        "purpose": "Exact serialized fields AND entry offsets, not merely table counts",
        "source": "standalone Python decoder independently implemented from original UE2 C++ table operators",
        "originals_unchanged": True, "read_only": True,
        "cases": []
    }
    for file_id, game, version, licensee, relative in ADDITIONAL:
        original = args.prd_root / Path(relative)
        raw = original.read_bytes()
        sha = hashlib.sha256(raw).hexdigest()
        old = expected[file_id]
        if old["sha256"].lower() != sha:
            raise ValueError(f"Original source changed: {file_id}")
        source = inspect(raw)
        if (source["version"], source["licensee"]) != (version, licensee):
            raise ValueError(f"Original package version changed: {file_id}")
        for key in ("name_count", "import_count", "export_count"):
            if source[key] != old[key]:
                raise ValueError(f"Original table structure changed: {file_id}/{key}")
        out["cases"].append({
            "file_id":file_id,"game_id":game,"version":version,"licensee":licensee,
            "original_sha256":sha,
            "name_rows":source["name_count"],"import_rows":source["import_count"],
            "export_rows":source["export_count"],
            "names_all_fields_sha256":source["all_name_fields_sha256"],
            "imports_all_fields_sha256":source["all_import_fields_sha256"],
            "exports_all_fields_sha256":source["all_export_fields_sha256"],
        })
    out["total_name_rows"] = sum(x["name_rows"] for x in out["cases"])
    out["total_import_rows"] = sum(x["import_rows"] for x in out["cases"])
    out["total_export_rows"] = sum(x["export_rows"] for x in out["cases"])
    args.output.write_text(json.dumps(out,indent=2,ensure_ascii=False)+"\n",encoding="utf-8")
    print(json.dumps({"ok":True,"cases":len(out["cases"]),
        "total_name_rows":out["total_name_rows"],"total_import_rows":out["total_import_rows"],
        "total_export_rows":out["total_export_rows"],"evidence":str(args.output)}))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
