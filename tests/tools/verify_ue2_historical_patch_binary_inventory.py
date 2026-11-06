#!/usr/bin/env python3
"""Audit historical UE2 update archives and recovered native binaries, no execution.
All producer/version claims below are derived from original package bytes in the
vendor patch archives. This is NOT a VerifyImport/dependency semantic oracle.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import struct
from pathlib import Path

UT2003_BUILDS = [
    ("2111beta2", "21112", 121, 28),
    ("2136release", "2136", 121, 29),
    ("2166", "2166", 121, 29),
    ("2186release", "2186", 121, 29),
    ("2199", "2199", 121, 29),
    ("2225", "2225", 121, 29),
]
UT2004_BUILDS = [
    ("3204", 127, 29), ("3236", 127, 29), ("3270", 127, 29),
    ("3323", 128, 29), ("3339", 128, 29), ("3355", 128, 29),
    ("3369", 128, 29),
]
PREFIX = b"lftp/4.9.3\n\n"
TAG = bytes.fromhex("c1 83 2a 9e")

def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def package_id(data: bytes, context: str) -> dict:
    if len(data) < 36 or data[:4] != TAG:
        raise ValueError(f"invalid original Unreal package header: {context}")
    v = struct.unpack_from("<I", data, 4)[0]
    return {"engine_version": v & 0xffff, "licensee_version": v >> 16}

def file_evidence(path: Path) -> dict:
    data = path.read_bytes()
    return {"bytes": len(data), "sha256": digest(data)}

def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--updates", type=Path, default=Path("L:/Source/Updates"))
    ap.add_argument("--analysis", type=Path, default=Path("D:/Temp/UnrealDB/UE2/update-native-evidence"))
    ap.add_argument("--sandbox", type=Path, default=Path("D:/Temp/UnrealDB/UE2/windows-sandbox"))
    ap.add_argument("--output", type=Path)
    args = ap.parse_args()
    evidence = {
        "schema": "ue2-authentic-updates-native-revisions-v1",
        "original_update_archives_modified": False,
        "original_game_packages_modified": False,
        "patched_executables_executed_on_host": False,
        "source_revisions_vs_exact_import_verification": "Core.u emitted package versions prove their own byte format, not compiled Core.dll VerifyImport equivalence",
        "ut2003": [], "ut2004": [], "sandbox": [],
    }
    for name, folder, version, licensee in UT2003_BUILDS:
        installer = args.updates / "ut2003" / f"ut2003-winpatch{name}.exe"
        system = args.analysis / "ut2003" / folder / "UT2003-Patch" / "System"
        native = {key: file_evidence(system / key)
                  for key in ("Core.dll", "Engine.dll", "Core.u", "Engine.u", "UCC.exe", "UT2003.exe")}
        identity = package_id((system / "Core.u").read_bytes(), str(system))
        if identity != {"engine_version": version, "licensee_version": licensee}:
            raise ValueError(f"UT2003 native Core.u identity mismatch in {name}")
        entry = {"patch": name, "source_archive": str(installer),
                 "source_archive_evidence": file_evidence(installer),
                 "core_package_identity": identity, "native_files": native}
        ini = system / "Build.ini"
        if ini.is_file():
            entry["build_ini"] = ini.read_text(encoding="latin1").strip()
        evidence["ut2003"].append(entry)

    manifest_path = args.analysis / "ut2004" / "ut2004-patch-native-binary-evidence.json"
    saved = json.loads(manifest_path.read_text(encoding="utf-8-sig"))
    manifest = {str(e["patch"]): e for e in saved["patches"]}
    for name, version, licensee in UT2004_BUILDS:
        archive = args.updates / "ut2004" / f"ut2004-winpatch{name}.exe"
        repaired = args.analysis / "ut2004" / "repaired-installers" / archive.name
        system = args.analysis / "ut2004" / name / "UT2004-Patch" / "System"
        original = archive.read_bytes()
        recovered = repaired.read_bytes()
        if not original.startswith(PREFIX) or original[len(PREFIX):] != recovered:
            raise ValueError(f"UT2004 wrapper recovery mismatch: {name}")
        if recovered[:2] != b"MZ":
            raise ValueError(f"not recovered Windows PE: {name}")
        off = struct.unpack_from("<I", recovered, 0x3c)[0]
        if recovered[off:off+4] != b"PE\x00\x00":
            raise ValueError(f"no recovered PE header: {name}")
        prior = manifest[name]
        if digest(original) != prior["original_sha256"].lower() or digest(recovered) != prior["repaired_pe_sha256"].lower():
            raise ValueError(f"UT2004 recorded wrapper hash drift: {name}")
        identity = package_id((system / "Core.u").read_bytes(), str(system))
        if identity != {"engine_version": version, "licensee_version": licensee}:
            raise ValueError(f"UT2004 native Core.u package identity changed: {name}")
        native = {key: file_evidence(system / key)
                  for key in ("Core.dll", "Engine.dll", "Core.u", "Engine.u", "UCC.exe", "UT2004.exe")}
        evidence["ut2004"].append({
            "patch": name, "source_archive": str(archive),
            "archive_size": len(original), "archive_sha256": digest(original),
            "unexpected_download_prefix": PREFIX.decode("ascii").replace("\n", "\\n"),
            "prefix_bytes_removed_from_copy": len(PREFIX),
            "recovered_pe_sha256": digest(recovered),
            "core_package_identity": identity,
            "native_files": native, "static_extraction_only": True,
        })

    for name in ("ut2004-winpatch3369", "u2_retail_031403_uk", "6497-7710_u2xmp"):
        output = args.sandbox / "output" / name
        log_path = output / "sandbox-execution.log"
        if not log_path.exists():
            raise ValueError(f"Sandbox log missing: {name}")
        text = log_path.read_text(encoding="utf-8-sig")
        extracted = sorted(str(p.relative_to(output)) for p in output.rglob("*") if p.is_file()
                           and p.name != "sandbox-execution.log")
        evidence["sandbox"].append({
            "name": name, "log_sha256": digest(log_path.read_bytes()),
            "network_disabled_declared": "NETWORK_DISABLED_BY_SANDBOX_CONFIGURATION" in text,
            "installer_started": "INSTALLER_STARTED PID=" in text,
            "installer_exit_reported": "INSTALLER_EXIT_CODE=" in text,
            "installer_platform_rejected": "not a valid application for this OS platform" in text,
            "captured_files": extracted,
            "interpretation": "No Core/Engine files recovered by the isolated installer run; not native-loader proof",
        })

    rea = args.analysis / "ut2003" / "rea-core-2136-inspect.json"
    if rea.is_file():
        rea_bytes = rea.read_bytes()
        rea_text = (rea_bytes.decode("utf-16") if rea_bytes.startswith(b"\xff\xfe")
                    else rea_bytes.decode("utf-8-sig"))
        result = json.loads(rea_text)
        observed = result.get("normalized_result", {})
        if result["subject"]["digest"]["sha256"].lower() != evidence["ut2003"][1]["native_files"]["Core.dll"]["sha256"]:
            raise ValueError("REA inspected different UT2003 v2136 Core DLL")
        evidence["rea_static_ut2003_2136"] = {
            "binary_sha256": result["subject"]["digest"]["sha256"],
            "analysis_provider": result["analysis_profile"]["parameters"]["upstream_analysis_profile"]["provider"]["id"],
            "procedure_count": observed.get("procedure_count"),
            "string_count": observed.get("string_count"),
            "import_verify_behavior_reconstructed": False,
        }

    evidence["summary"] = {
        "ut2003_authentic_compiled_patch_builds": len(evidence["ut2003"]),
        "ut2003_patches_package_121_licensee_29": sum(1 for e in evidence["ut2003"] if e["core_package_identity"] == {"engine_version":121,"licensee_version":29}),
        "ut2004_authentic_compiled_patch_builds": len(evidence["ut2004"]),
        "ut2004_patches_package_127_licensee_29": sum(e["core_package_identity"]["engine_version"]==127 for e in evidence["ut2004"]),
        "ut2004_patches_package_128_licensee_29": sum(e["core_package_identity"]["engine_version"]==128 for e in evidence["ut2004"]),
        "game_specific_verify_import_certified_by_this_inventory": False,
        "original_files_reparsed_or_imported": 0,
    }
    payload = json.dumps(evidence, indent=2, ensure_ascii=False) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(payload, encoding="utf-8")
    print(json.dumps({"ok": True, "summary": evidence["summary"],
          "sandbox_cases": evidence["sandbox"], "output": str(args.output)}, indent=2))
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
