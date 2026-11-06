#!/usr/bin/env python3
r"""Pin authoritative game/UE2 C++ property stream and UStruct gates.

This script checks original source invariants and SHA256. This does not claim
that every historical produced object's native class payload was decoded.
"""
from __future__ import annotations
import hashlib,json,re
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
ENGINE=Path("L:/Source/Engine/UE2/Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]/Core")
SOURCE=[
  ("engine-ue2.5",ENGINE,None),
  ("unreal2-january2003",Path("L:/Source/Games/Unreal II/Unreal II The Awakening [01-07-2003]/Core"),"engine_version>=118"),
  ("ut2003-v2107",Path("L:/Source/Games/UT2003/Unreal Tournament 2003 [v2107] [2002-10-01]/Core"),"licensee_version>=28"),
  ("ut2004-v3186",Path("L:/Source/Games/UT2004/Unreal Tournament 2004 - v3186/Core"),"licensee_version>=28"),
  ("ut2004-v3369",Path("L:/Source/Games/UT2004/Unreal Tournament 2004 [v3369] [03-16-2004]/Core"),"licensee_version>=28"),
  ("ut2004-later",Path("L:/Source/Games/UT2004/UT2004Src/UT2004SrcCmake/Core"),"licensee_version>=28"),
]
def read(file:Path)->str:
    return file.read_text(encoding="latin-1")
def require(text:str,pattern:str, context:str):
    if not re.search(pattern,text,flags=re.S):
        raise ValueError(f"Source claim not proved by {context}: {pattern}")
def sha(path:Path)->str:
    return hashlib.sha256(path.read_bytes()).hexdigest()
def slice_fn(source:str,start:str,window:int=12000)->str:
    pos=source.find(start)
    if pos<0:raise ValueError("Missing source function "+start)
    return source[pos:pos+window]
def main():
    rows=[]
    for variant,base,struct_rule in SOURCE:
        unclass=base/"Src"/"UnClass.cpp"
        unprop=base/"Src"/"UnProp.cpp"
        unnames=base/"Inc"/"UnNames.h"
        assert unclass.is_file() and unprop.is_file() and unnames.is_file(),base
        c,p,n=read(unclass),read(unprop),read(unnames)
        prop=slice_fn(c,"struct FPropertyTag",11500)
        stream=slice_fn(p,"void UStructProperty::SerializeItem(",1100)
        cls=slice_fn(c,"void UStruct::Serialize(",3000)
        require(prop,r"Ar\s*<<\s*Tag\.Name[\s\S]*?if\s*\(\s*Tag\.Name\s*==\s*NAME_None",variant+" property termination")
        require(prop,r"Tag\.Type\s*=\s*Tag\.Info\s*&\s*0x0f",variant+" tag type width")
        require(prop,r"Tag\.Info\s*&\s*0x70",variant+" tag size code")
        require(prop,r"Tag\.Type\s*==\s*NAME_StructProperty[\s\S]{0,50}Ar\s*<<\s*Tag\.ItemName",variant+" tagged struct name")
        require(prop,r"Tag\.Info\s*&\s*0x80[\s\S]{0,75}Tag\.Type\s*!=\s*NAME_BoolProperty",variant+" bool bit")
        require(prop,r"Tag\.ArrayIndex\s*=\s*\(\(INT\)\(B&0x3F\)<<24\)",variant+" 4-byte array index")
        for suffix,value in [("ByteProperty",1),("IntProperty",2),("BoolProperty",3),("FloatProperty",4),
                 ("ObjectProperty",5),("NameProperty",6),("ArrayProperty",9),("StructProperty",10)]:
            require(n,rf"REGISTER_NAME\(\s*{value},\s*{suffix}\s*\)",variant+" property type "+suffix)
        require(stream,r"Struct->GetFName\(\)==NAME_Vector[\s\S]*?NAME_Rotator[\s\S]*?NAME_Color",variant+" vector exception")
        if struct_rule=="engine_version>=118":
            require(stream,r"Ar\.Ver\(\)\s*<\s*118",variant+" binary->tagged switch")
            require(cls,r"Ar\.Ver\(\)\s*>=\s*120[\s\S]{0,50}Ar\s*<<\s*CppText",variant+" CppText branch")
        elif struct_rule=="licensee_version>=28":
            require(stream,r"Ar\.LicenseeVer\(\)\s*<\s*0x1C",variant+" binary->tagged switch")
            require(cls,r"Ar\.LicenseeVer\(\)\s*>=\s*0x1A[\s\S]{0,60}Ar\s*<<\s*StructFlags",variant+" StructFlags branch")
        rows.append({
            "variant":variant,"source_rule":struct_rule,
            "native_struct_body_class_serializers_verified":False,
            "source_files":[{"path":str(f),"sha256":sha(f)} for f in (unclass,unprop,unnames)],
            "tag_header_name_info_type_struct_size_array_index":True,
            "tag_property_type_byte_int_bool_float_object_name_array_struct":True,
            "struct_property_binary_vector_rotator_color_exceptions":True
        })
        print("SOURCE_CLASS_STREAM_BOUNDARIES_PASS",variant,struct_rule,flush=True)
    result={
        "schema":"ue2-source-property-tag-and-struct-branch-review-v1",
        "source_authority_only":True,"reader_code_used":False,
        "class_payload_natively_verified":False,
        "original_game_or_engine_source_modified":False,
        "source_revisions":rows,
        "source_revision_count":len(rows),
    }
    output=ROOT/"tests/evidence/ue2/ue2-source-property-tag-and-struct-version-boundaries-20261106.json"
    output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print(json.dumps({"ok":True,"revisions":len(rows),"evidence":str(output)},indent=2))
if __name__=="__main__":main()
