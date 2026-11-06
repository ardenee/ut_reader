#!/usr/bin/env python3
"""Independent, read-only UE2 classic package wire-layout differential.

Source authority: original UE2/Unreal II, UT2003, UT2004 Core archive
serializers. This deliberately does not import any UnrealDB code and
does not parse class-specific export payloads or determine runtime
physical mount order. Bytes remain unmodified.

Examples:
  py -3 tests/tools/verify_ue2_source_binary_layout_independent.py
  py -3 tests/tools/verify_ue2_source_binary_layout_independent.py --prd-root "\\\\192.168.0.55\\c$\\Apache24\\htdocs\\unrealdb\\catalog"
"""
from __future__ import annotations

import argparse
import hashlib
import json
import struct
from pathlib import Path

MAGIC = 0x9E2A83C1
DEFAULT_ORIGINALS = Path(r"D:\Temp\UnrealDB\UE2\original-wire")
ADDITIONAL = [
    # Actual original game-labelled distributions, not just generated synthetic headers.
    (1277117,4,121,29,"storage/games/ut2003/verified/83a28f2edfccabc75ad0adf98ca3648d.usx"),
    (1282344,5,121,29,"storage/games/ut2004/verified/51831195ef2ddde67dbfc42105b26c17.u"),
    (1289524,5,118,2,"storage/games/ut2004/verified/f6f3367ed00fe2d3952ba8092c6b6a8a.u"),
    (111284,2,110,2481,"storage/games/unreal2/verified/c9bd2cc9787d1cbd064219d02f96aa5d.u"),
    (111218,2,110,2609,"storage/games/unreal2/verified/21c1c321bfdaba97bf8bcfef2b96f4ae.u"),
]


class WireError(ValueError):
    pass


class Wire:
    def __init__(self, raw: bytes, at: int = 0):
        self.raw = raw
        self.at = at

    def get(self, n: int) -> bytes:
        if n < 0 or self.at < 0 or self.at + n > len(self.raw):
            raise WireError(f"Read beyond file at {self.at} for {n} bytes / {len(self.raw)}")
        result = self.raw[self.at:self.at+n]
        self.at += n
        return result

    def u8(self) -> int:
        return self.get(1)[0]

    def i32(self) -> int:
        return struct.unpack("<i", self.get(4))[0]

    def u32(self) -> int:
        return struct.unpack("<I", self.get(4))[0]

    def ci(self) -> int:
        first = self.u8()
        sign = bool(first & 0x80)
        total = first & 0x3F
        if first & 0x40:
            for i in range(1, 5):
                b = self.u8()
                total |= (b & (0xFF if i == 4 else 0x7F)) << (6 + 7 * (i-1))
                if i == 4 or not b & 0x80:
                    break
            else:
                raise WireError("Compact index exceeds 5 bytes")
        if total > 0x80000000 or (not sign and total > 0x7FFFFFFF):
            raise WireError("CompactIndex exceeds signed 32-bit integer range")
        return -total if sign else total

    def ansi_z(self) -> str:
        stop = self.raw.find(b"\0", self.at)
        if stop < 0:
            raise WireError("Unterminated legacy FName")
        return self.get(stop-self.at+1)[:-1].decode("latin-1")

    def fstring(self) -> str:
        char_count = self.ci()
        if char_count == 0:
            return ""
        if char_count > 0:
            raw = self.get(char_count)
            return raw.split(b"\0",1)[0].decode("latin-1")
        raw = self.get((-char_count)*2)
        text = raw.decode("utf-16-le", errors="strict")
        return text.split("\0",1)[0]


def inspect(raw: bytes) -> dict:
    f = Wire(raw)
    magic, packed, flags = f.u32(), f.u32(), f.u32()
    if magic != MAGIC:
        raise WireError(f"Invalid UE2 package magic {magic:08X}")
    version, licensee = packed & 0xFFFF, packed >> 16
    n_count, n_off = f.i32(), f.i32()
    e_count, e_off = f.i32(), f.i32()
    i_count, i_off = f.i32(), f.i32()
    for count, offset, field in ((n_count,n_off,"names"),(e_count,e_off,"exports"),(i_count,i_off,"imports")):
        if count < 0 or count > len(raw) or offset < 0 or (count > 0 and offset >= len(raw)):
            raise WireError(f"Invalid {field} count or file offset")
    if version >= 68:
        guid = f.get(16)
        generations = f.i32()
        if generations < 0 or generations > (len(raw)-f.at)//8:
            raise WireError("Invalid generation count")
        generation_rows = [(f.i32(),f.i32()) for _ in range(generations)]
        heritage = None
    else:
        h_count, h_off = f.i32(), f.i32()
        if h_count < 0 or h_off < 0 or (h_count and h_off+16*h_count > len(raw)):
            raise WireError("Invalid heritage GUID table")
        guid = raw[h_off+16*(h_count-1):h_off+16*h_count] if h_count else b""
        heritage = (h_count,h_off)
        generation_rows = [(e_count,n_count)]
    header_end = f.at
    f.at = n_off
    names = []
    for _ in range(n_count):
        text = f.ansi_z() if version < 64 else f.fstring()
        flags32 = f.u32()
        names.append((text,flags32))
    names_end = f.at

    def fname():
        idx = f.ci()
        if idx < 0 or idx >= len(names):
            raise WireError(f"FName index {idx} outside NameMap[{len(names)}]")
        return idx

    f.at = i_off
    imports = []
    for _ in range(i_count):
        cls_pkg, cls_name = fname(), fname()
        outer, obj_name = f.i32(), fname()
        if outer > 0 or outer < -i_count:
            raise WireError("Invalid UE2 import parent")
        imports.append((cls_pkg,cls_name,outer,obj_name))
    imports_end = f.at

    f.at = e_off
    exports = []
    for _ in range(e_count):
        klass, superclass, outer = f.ci(), f.ci(), f.i32()
        obj_name, obj_flags, serial_size = fname(), f.u32(), f.ci()
        serial_offset = f.ci() if serial_size else 0
        for ref in (klass,superclass,outer):
            if ref < -i_count or ref > e_count:
                raise WireError("Export references outside signed import/export tables")
        if serial_size > 0 and (serial_offset < 0 or serial_offset+serial_size > len(raw)):
            raise WireError("Export payload span outside original package")
        exports.append((klass,superclass,outer,obj_name,obj_flags,serial_size,serial_offset))
    exports_end = f.at
    return dict(version=version,licensee=licensee,tag=f"{magic:08X}",
                package_flags=f"{flags:08X}",summary_bytes=header_end,
                guid_bytes=len(guid),generations=len(generation_rows),
                heritage=heritage,name_count=len(names),import_count=len(imports),
                export_count=len(exports),name_end=names_end,
                import_end=imports_end,export_end=exports_end,
                distinct_name_flags=len({x[1] for x in names}),
                names_non_ascii=sum(any(ord(ch)>127 for ch in x[0]) for x in names),
                payload_exports=sum(x[5]>0 for x in exports),
                signed_export_sizes=sum(x[5]<0 for x in exports))


def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument("--fixture-root",type=Path,default=DEFAULT_ORIGINALS)
    ap.add_argument("--prd-root",type=Path)
    options=ap.parse_args()
    manifest=json.loads((options.fixture_root/"manifest.json").read_text(encoding="utf-8-sig"))
    original=[]
    for item in manifest:
        raw_path=options.fixture_root/(str(item["id"])+".bin")
        raw=raw_path.read_bytes()
        sha=hashlib.sha256(raw).hexdigest()
        if sha.lower()!=item["sha256"].lower(): raise WireError(f"Hash mismatch: {raw_path}")
        decoded=inspect(raw)
        for k in ("version","licensee","names","imports","exports"):
            key={"names":"name_count","imports":"import_count","exports":"export_count"}.get(k,k)
            if decoded[key]!=item[k]: raise WireError(f"Format drift: file {item['id']} {k}: {decoded[key]} != {item[k]}")
        original.append({"id":item["id"],"sha256":sha,**decoded})
    extra=[]
    if options.prd_root:
        for file_id,game,version,lic,relative in ADDITIONAL:
            path=options.prd_root / Path(relative)
            raw=path.read_bytes()
            decoded=inspect(raw)
            if (decoded["version"],decoded["licensee"])!=(version,lic):
                raise WireError(f"Original source version mismatch on {path}")
            extra.append({"id":file_id,"game":game,"sha256":hashlib.sha256(raw).hexdigest(),**decoded})
    assert len(original)==10

    # In-memory corruption probes: original bytes are never modified.
    seed=(options.fixture_root/(str(manifest[0]["id"])+".bin")).read_bytes()
    invalid_cases={
        "bad_tag": bytes(4)+seed[4:],
        "truncated_summary": seed[:10],
        "negative_name_count": seed[:12]+struct.pack("<i",-1)+seed[16:],
        "name_offset_beyond_eof": seed[:16]+struct.pack("<i",len(seed)+100)+seed[20:],
    }
    for label,bad in invalid_cases.items():
        try:
            inspect(bad)
        except (WireError,IndexError,UnicodeDecodeError,struct.error):
            pass
        else:
            raise WireError(f"Malformed original wrongly accepted: {label}")
    for label,bad in {"compact_i32_overflow":bytes.fromhex("40 80 80 80 ff"),
                      "truncated_fstring":bytes.fromhex("05 61 62")}.items():
        try:
            if label.startswith("compact"): Wire(bad).ci()
            else: Wire(bad).fstring()
        except (WireError,IndexError):
            pass
        else:
            raise WireError(f"Malformed synthetic type wrongly accepted: {label}")

    print(json.dumps({"ok":True,"standalone":True,"originals":len(original),
        "negative_probes":list(invalid_cases)+["compact_i32_overflow","truncated_fstring"],
        "new_version_cohorts":len(extra),"fixture_manifest":str(options.fixture_root/"manifest.json"),
        "old_versions":[{"id":o["id"],"v":o["version"],"lic":o["licensee"],"names":o["name_count"],"imports":o["import_count"],"exports":o["export_count"]} for o in original],
        "new_cases":extra},indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
