#!/usr/bin/env python3
"""Read-only T019 original v432 locale diagnostic, NOT a T019 closure."""
from pathlib import Path
import hashlib,json,sys
R=Path(__file__).resolve().parents[2]
P=R/'catalog/compliance/evidence/o06'
m=json.loads((P/'20261031-G3-T019-original-v432-direct-localize-discrepancy.json').read_text(encoding='utf-8'))
err=[];n=0
def ck(ok,msg):
 global n
 n+=1
 if not ok:err.append(msg)
ck(m['test']=='T019' and m['status']=='BLOCKED' and m['full_T019_pass'] is False,'must keep frozen native T019 blocked')
ck(hashlib.sha256(Path(r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll').read_bytes()).hexdigest()==m['original_native_engine_binary_sha'],'real stock v432 original compiled Core.dll SHA')
ck(hashlib.sha256(Path(r'C:\Temp\UnrealDB\O06\Native-UCC\System\G3V432LocaleProbe.u').read_bytes()).hexdigest()==m['original_compiled_UCC_localization_probe_build_SHA256'],'original compiler compiled distinct locale diagnostic')
src=Path(r'L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnObj.cpp')
ck(hashlib.sha256(src.read_bytes()).hexdigest()=='4dd6a4de7b5584015aa5ac79501cca4baca5315df4c65f6b0ae746ab9e1de3b2','source build selected LoadLocalized implementation SHA')
ck(b'if( GIsEditor )' in src.read_bytes() and b'const TCHAR* Text = Localize' in src.read_bytes(),'source-selected GIsEditor localization and native direct lookup')
for entry in m['source_sha_items']:
 f=R/entry['path']
 ck(f.is_file() and f.stat().st_size==entry['bytes'],'pinned input/output byte count '+entry['path'])
 ck(f.is_file() and hashlib.sha256(f.read_bytes()).hexdigest()==entry['sha256'],'original native input/output SHA '+entry['path'])
for code,word in ((333,'Native Epsilon'),(444,'Native Zeta')):
 f=P/f'20261031-G3-T019-original-v432-direct-localize-{code}.log'
 t=f.read_bytes().decode('utf-8','replace')
 ck(f'G3_T019_V432_DIRECT_LOCALIZE={word}' in t,'direct original native localization recognized external .int '+str(code))
 ck(f'G3_T019_V432_NOEDITOR_CONFIG={code}' in t,'genuine INI config loaded in same original process '+str(code))
 ck('G3_T019_V432_NOEDITOR_LOCALIZED=DefaultBulk' in t,'original default object NOT changed by auto-locale '+str(code))
 ck('G3_T019_V432_NOEDITOR_TRANSIENT=0' in t and 'G3_T019_V432_NOEDITOR_PERSIST=888' in t,'original runtime transient and persisted field semantics '+str(code))
ck(len(m['two_new_original_native_observations'])==2,'exact two new original native processes')
out={'ok':not err,'checks':n,'errors':err,'test':'T019','status':'BLOCKED',
 'original_v432_direct_Localize_function_reads_external_int':True,
 'original_v432_class_default_localization_override_not_observed':True,
 'native_original_compiled_sessions_repeated':0,'T019_FULLY_ACCEPTED':False,
 'source_scope':'Original stock UT99 v432 only, C98 and 2012 C227i not inferred',
 'original_script_editor_gate':'UObject::LoadLocalized returns in editor; exact invocation phase must be proven before acceptance'}
print(json.dumps(out,indent=2))
sys.exit(0 if not err else 1)
