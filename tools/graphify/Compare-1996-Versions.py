import hashlib
from pathlib import Path
a=Path(r"L:\Source\Games\Unreal\Unreal [v0.82] [1996-00-00]\SRC")
b=Path(r"L:\Source\Games\Unreal\Unreal [v0.83] [1996-10-01]\Src")
def files(root):
    out={}
    for p in root.rglob("*"):
        if p.is_file() and p.suffix.lower() in (".cpp",".h"):
            key=p.relative_to(root).as_posix().lower()
            out[key]=hashlib.sha256(p.read_bytes()).hexdigest()
    return out
x=files(a);y=files(b)
common=set(x)&set(y)
d=[p for p in sorted(common) if x[p]!=y[p]]
print("V082_CODE",len(x),"V083_CODE",len(y),"COMMON",len(common),"IDENTICAL",len(common)-len(d),"DIFFERENT",len(d))
print("V082_ONLY",len(set(x)-set(y)),"V083_ONLY",len(set(y)-set(x)))
print("DIFFERENT_FIRST",d[:35])
print("V082_ONLY_FIRST",sorted(set(x)-set(y))[:20])
print("V083_ONLY_FIRST",sorted(set(y)-set(x))[:20])
manifest="\n".join(f"{path}:{x[path]}" for path in sorted(x))
print("VERSION_9_SOURCE_MANIFEST_SHA256",hashlib.sha256(manifest.encode("ascii")).hexdigest())
if x!=y:
    raise SystemExit("FAIL: v0.82/v0.83 code-source identity mismatch")
print("SOURCE_IDENTITY_PASS: 200/200 identical C/C++ files, zero missing")
