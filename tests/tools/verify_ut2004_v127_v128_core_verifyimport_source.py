#!/usr/bin/env python3
"""Source-only oracle: compare the complete UT2004 v127, v128, v129 VerifyImport.

No UnrealDB logic, raw package data, or game's native runtime is involved.
The original game source files are read-only. This test guards the specific
permission to use v128 VerifyImport with an original v127 stored package.
"""
from __future__ import annotations

import argparse
import difflib
import hashlib
import json
import re
from pathlib import Path

DEFAULT_ROOT = Path(r"L:\Source\Games\UT2004")
SOURCES = {
    "v127": (
        "Unreal Tournament 2004 - v3186/Core/Src/UnLinker.cpp",
        "8211e91df606390cea9e3758e17884c3b41238c6f0195f90a40c55c5ec7252d7",
    ),
    "v128": (
        "Unreal Tournament 2004 [v3369] [03-16-2004]/Core/Src/UnLinker.cpp",
        "127927aef72aba2f405f9756069b2992c2c5bd2d42ca768d5913fcb60996d0a5",
    ),
    "v129": (
        "UT2004Src/UT2004SrcCmake/Core/Src/UnLinker.cpp",
        "171b005d96292a420b64d746ae1669e35e6ac6c953d700a7688bf5cf560fc22f",
    ),
}


def method_tokens(text: str) -> list[str]:
    identifier = "void ULinkerLoad::VerifyImport("
    start = text.find(identifier)
    if start < 0:
        raise ValueError("VerifyImport source body missing")
    opening = text.find("{", start)
    depth = 0
    ending = None
    for i in range(opening, len(text)):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                ending = i + 1
                break
    if ending is None:
        raise ValueError("VerifyImport source body is incomplete")
    body = re.sub(r"/\*.*?\*/|//[^\n]*", "", text[opening:ending], flags=re.S)
    if re.search(r"\bArVer\b|\bArLicenseeVer\b|Summary\.GetFileVersion", body):
        raise ValueError("Version-specific VerifyImport branch detected")
    return re.findall(r"[A-Za-z_]\w*|(?:0x)?[0-9]+|\S", body)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--source-root", type=Path, default=DEFAULT_ROOT)
    ap.add_argument("--output", type=Path)
    args = ap.parse_args()
    methods = {}
    filenames = {}
    sha = {}
    for variant, (relative, expected) in SOURCES.items():
        path = args.source_root / relative
        raw = path.read_bytes()
        digest = hashlib.sha256(raw).hexdigest()
        if digest != expected:
            raise ValueError(f"Original game source hash changed for {variant}: {digest}")
        methods[variant] = method_tokens(raw.decode("latin-1"))
        filenames[variant] = relative
        sha[variant] = digest
    tokens = methods["v127"]
    if methods["v128"] != tokens:
        raise ValueError("v127/v128 VerifyImport semantics diverged")
    diff = difflib.SequenceMatcher(
        a=tokens, b=methods["v129"], autojunk=False
    ).get_opcodes()
    additions = [
        methods["v129"][new_start:new_end]
        for operation, old_start, old_end, new_start, new_end in diff
        if operation != "equal"
    ]
    if additions != [["*"], ["*"]]:
        raise ValueError("v129 VerifyImport changed beyond two localization argument dereferences")
    out = {
        "ok": True,
        "authority": "Original Epic UT2004 v3186/v3369/v129 game Core UnLinker.cpp source files",
        "source_only": True,
        "source_paths": filenames,
        "source_file_sha256": sha,
        "v127_v128_exact_verifyimport_token_equivalence": True,
        "v127_v128_token_count": len(tokens),
        "verifyimport_explicit_version_or_licensee_branches": False,
        "v129_semantic_delta": "Two unary * dereferences of LocalizeError results before appThrowf; no import matching change",
        "v127_stored_with_v128_loader_policy_is_source_compatible": True,
        "v118_v121_native_loader_acceptance_established": False,
    }
    payload = json.dumps(out, indent=2, ensure_ascii=False) + "\n"
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(payload, encoding="utf-8")
    print(payload, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
