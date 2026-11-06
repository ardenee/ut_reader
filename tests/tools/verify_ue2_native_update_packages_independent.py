#!/usr/bin/env python3
r"""Read-only independent UE2 source-wire verification of genuine archived Core.u/Engine.u.
Reuses existing source-derived wire decoder and SHA-pinned extracted-binary provenance.
Never executes original game binaries, mutates archives, or accesses PRD.
"""
from __future__ import annotations
import argparse,hashlib,json,sys
from pathlib import Path
from datetime import datetime,timezone

sys.path.insert(0,str(Path(__file__).resolve().parent))
from verify_ue2_source_binary_layout_independent import inspect,WireError
ROOT=Path(__file__).resolve().parents[2]
DEFAULT_NATIVE=Path("D:/Temp/UnrealDB/UE2/native-version-archive")
DEFAULT_OUT=ROOT/"tests/evidence/ue2/ue2-authentic-native-build-script-packages-20261106.json"

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--archive-root",type=Path,default=DEFAULT_NATIVE)
    parser.add_argument("--output",type=Path,default=DEFAULT_OUT)
    args=parser.parse_args()
    archive=args.archive_root
    manifest_path=archive/"manifest.json"
    manifest_bytes=manifest_path.read_bytes()
    provenance=json.loads(manifest_bytes.decode("utf-8-sig"))
    builds=provenance["versions"]
    if len(builds)!=15:
        raise RuntimeError(f"Expected 15 recovered binary builds, found {len(builds)}")
    cases=[]
    mismatches=[]
    for build in builds:
        records={x["name"].lower():x for x in build["files"]}
        identities={}
        for basename in ("Core.u","Engine.u"):
            name=basename.lower()
            original=records.get(name)
            if not original:raise RuntimeError(f"Missing {basename} in {build['game']}/{build['version']}")
            path=archive/build["game"]/build["version"]/basename
            raw=path.read_bytes()
            digest=hashlib.sha256(raw).hexdigest()
            if digest!=original["sha256"]:
                raise RuntimeError(f"Native archive SHA drift {path}")
            actual=inspect(raw)
            identities[name]=(actual["version"],actual["licensee"])
            cases.append({
                "game":build["game"],"build":build["version"],
                "distribution_kind":build["origin"],"script_package":basename,
                "archive_relative_path":f"{build['game']}/{build['version']}/{basename}",
                "sha256":digest,"bytes":len(raw),
                "version":actual["version"],"licensee":actual["licensee"],
                "summary_bytes":actual["summary_bytes"],
                "generations":actual["generations"],
                "name_count":actual["name_count"],
                "import_count":actual["import_count"],
                "export_count":actual["export_count"],
                "non_ascii_names":actual["names_non_ascii"],
                "export_payload_count":actual["payload_exports"],
                "negative_export_sizes":actual["signed_export_sizes"],
                "full_name_fields_sha256":actual["all_name_fields_sha256"],
                "full_import_fields_sha256":actual["all_import_fields_sha256"],
                "full_export_fields_sha256":actual["all_export_fields_sha256"],
            })
        if identities["core.u"]!=identities["engine.u"]:
            mismatches.append({"game":build["game"],"build":build["version"],
                "core_package_version":list(identities["core.u"]),
                "engine_package_version":list(identities["engine.u"])})
    summary={
        "binary_builds":len(builds),"script_packages":len(cases),
        "name_rows":sum(x["name_count"] for x in cases),
        "import_rows":sum(x["import_count"] for x in cases),
        "export_rows":sum(x["export_count"] for x in cases),
        "non_ascii_name_rows":sum(x["non_ascii_names"] for x in cases),
        "positive_export_spans_checked":sum(x["export_payload_count"] for x in cases),
        "negative_signed_export_sizes":sum(x["negative_export_sizes"] for x in cases),
        "cross_package_version_variations":mismatches,
    }
    result={
        "schema":"ue2-source-wire-authentic-compiled-patch-script-package-differential-v1",
        "authority":"Existing independent original UE2 Core source-derived wire decoder",
        "provenance_sha256":hashlib.sha256(manifest_bytes).hexdigest(),
        "read_only":True,"game_binaries_executed":False,
        "scope":"complete serialized summary/Name/Import/Export fields and entry offsets of Core.u and Engine.u, not export-body/VerifyImport native semantics",
        "summary":summary,"cases":cases,
    }
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print(json.dumps({"ok":True,"summary":summary,"evidence":str(args.output)},indent=2))
if __name__=="__main__":main()
