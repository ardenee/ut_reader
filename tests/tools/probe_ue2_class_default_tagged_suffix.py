#!/usr/bin/env python3
r"""Find source-authored UClass default tagged-property suffixes in original packages.

UClass::Serialize writes tagged class defaults *at the end* of UClass payload.
A dynamic-programming suffix match is exploratory until validated against real
property export names and the source branch. No guessed class offsets accepted.
"""
from __future__ import annotations
import sys,struct,json
from pathlib import Path
sys.path.insert(0,"C:/Users/arden/source/repos/ut_reader/tests/tools")
from verify_ue2_source_binary_layout_independent import Wire,WireError
ROOT=Path("D:/Temp/UnrealDB/UE2/native-version-archive")
CASES=[
"unreal2/retail-USA-original-v110-lic2481/Engine.u",
"unreal2/special-edition-SP-original-v110-lic2609/Engine.u",
"ut2003/patch-2136/Engine.u","ut2003/patch-2225/Engine.u",
"ut2004/patch-3204/Engine.u","ut2004/patch-3369/Engine.u",
]
PROPERTY_IDS={"ByteProperty":1,"IntProperty":2,"BoolProperty":3,"FloatProperty":4,"ObjectProperty":5,"NameProperty":6,"DelegateProperty":7,"ClassProperty":8,"ArrayProperty":9,"StructProperty":10,"VectorProperty":11,"RotatorProperty":12,"StrProperty":13,"MapProperty":14,"FixedArrayProperty":15}
PROPERTY_CLASS_NAMES={"ByteProperty","IntProperty","BoolProperty","FloatProperty",
"ObjectProperty","NameProperty","DelegateProperty","ClassProperty","ArrayProperty",
"StructProperty","VectorProperty","RotatorProperty","StrProperty","MapProperty","FixedArrayProperty"}

def read_package(path):
    raw=path.read_bytes();x=Wire(raw);x.u32();packed=x.u32();x.u32()
    nc,no,ec,eo,ic,io=[x.i32() for _ in range(6)]
    if (packed&65535)>=68:
        x.get(16);ng=x.i32();x.get(8*ng)
    else:x.get(8)
    x.at=no;names=[]
    for _ in range(nc):
        names.append(x.fstring() if (packed&65535)>=64 else x.ansi_z())
        x.u32()
    x.at=io;imports=[]
    for _ in range(ic):
        imports.append((x.ci(),x.ci(),x.i32(),x.ci()))
    x.at=eo;exports=[]
    for i in range(ec):
        cls=x.ci();sup=x.ci();outer=x.i32();name=x.ci();fl=x.u32()
        size=x.ci();offset=x.ci() if size else 0
        cname=names[imports[-cls-1][3]] if cls<0 else ("Class" if cls==0 else "export")
        exports.append({"class":cname,"name":names[name],"flags":fl,
                        "size":size,"offset":offset})
    property_names={e["name"] for e in exports if e["class"] in PROPERTY_CLASS_NAMES}
    property_types={}
    for e in exports:
        if e["class"] in PROPERTY_IDS:
            property_types.setdefault(e["name"],set()).add(PROPERTY_IDS[e["class"]])
    return raw,names,exports,property_names,property_types,packed

def tag_at(data,pos,end,names,property_names):
    try:
        w=Wire(data,pos)
        idx=w.ci()
        if idx<0 or idx>=len(names):return None
        name=names[idx]
        if name=="None":
            if w.at==end:return (w.at, None)
            return None
        if name not in property_names:return None
        info=w.u8();typ=info&0x0f
        if typ<1 or typ>15:return None
        struct_name=None
        if typ==10:
            ns=w.ci()
            if ns<0 or ns>=len(names):return None
            struct_name=names[ns]
        sz=info&0x70
        if sz in (0,16,32,48,64):length={0:1,16:2,32:4,48:12,64:16}[sz]
        elif sz==0x50:length=w.u8()
        elif sz==0x60:length=struct.unpack("<H",w.get(2))[0]
        else:length=w.i32()
        if length<0 or (typ==3 and length!=0):return None
        array_index=0
        if info&0x80 and typ!=3:
            b=w.u8()
            if b<0x80:array_index=b
            elif b<0xc0:array_index=((b&0x7f)<<8)|w.u8()
            else:array_index=((b&0x3f)<<24)|(w.u8()<<16)|(w.u8()<<8)|w.u8()
        start_payload=w.at
        if w.at+length>end:return None
        w.get(length)
        return (w.at, {"name":name,"type":typ,"size":length, "value_start":start_payload, "value_end":w.at,
                       "info":info,"array_index":array_index,
                       "struct_name":struct_name,"header_bytes":start_payload-pos})
    except (WireError,UnicodeDecodeError,ValueError,IndexError):
        return None

results=[]
for rel in CASES:
    raw,names,exports,properties,property_types,packed=read_package(ROOT/rel)
    selected=[e for e in exports if e["class"]=="Class" and
              e["name"] in ("Actor","Pawn","PlayerPawn","Texture","Material","Decoration") and
              0<e["size"]<30000]
    for e in selected[:4]:
        begin=e["offset"];end=begin+e["size"]
        dp={end:(0,[])}
        for off in range(end-1,begin-1,-1):
            token=tag_at(raw,off,end,names,properties)
            if not token:continue
            next_pos,tag=token
            if next_pos in dp:
                count,chain=dp[next_pos]
                dp[off]=(count+(tag is not None),([tag] if tag else [])+chain)
        chains=[(start,record) for start,record in dp.items() if record[0]>=3]
        if not chains:
            results.append({"asset":rel,"class":e["name"],"success":False,
                            "reason":"no >=3-tag class default suffix reconstructed"})
            continue
        first,(num,tags)=max(chains,key=lambda kv:kv[1][0])
        matching=sum(t["type"] in property_types.get(t["name"],set()) for t in tags)
        results.append({"asset":rel,"class":e["name"],"success":True,
                        "size":e["size"],"default_suffix_offset":first,
                        "tagged_property_count":num,"type_matches_declaration":matching,
                        "tagged_record_example":tags[:8], "all_tagged_records":tags,
                        "property_name_refs_all_known_exports":True})
print(json.dumps({"results":results,
    "pass_example_suffices":sum(x["success"] for x in results),
    "candidate_class_bodies":len(results),
    "limitations":"heuristic locating start of class default stream; parser and property names authoritative, but actual start not traced from native serializer"},indent=2))
