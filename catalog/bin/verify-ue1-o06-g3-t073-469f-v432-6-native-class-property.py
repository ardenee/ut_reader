#!/usr/bin/env python3
"""Read-only 469f-v432 original native scoped method differential. No original rerun."""
from pathlib import Path
import hashlib,json,re,sys
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
m=json.loads((EV/'20261101-G3-T073-469f-v432-six-native-Class-Property-v61-v62-parity.json').read_text())
issues=[];n=0
def ck(ok,label):
 global n
 n+=1
 if not ok:issues.append(label)
ck(m['new_true_original_469f_compiled_native_UStruct_UClass_UProperty_method_cases']==6,
   'six new separate original compiled 469f methods')
ck(m['original_native_reexecutions_of_accepted_v432']==0,'no prior accepted v432 source method rerun')
for p in m['original_469f_native_output_source_pins']:
 f=ROOT/p['path']
 ck(f.is_file() and f.stat().st_size==p['bytes'] and hashlib.sha256(f.read_bytes()).hexdigest()==p['sha256'],
    'original 469f real compiled C++ stdout/source/compile log unchanged '+p['path'])
s=Path(r'C:\Temp\UnrealDB\O06\Native-469f\System')
v=Path(r'C:\Temp\UnrealDB\O06\Native-UCC\System')
ck(hashlib.sha256((s/'Core.dll').read_bytes()).hexdigest()
   ==m['original_469f_Core_DLL_SHA256']=='80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2',
   'real shipped separate original 469f Core.dll SHA pinned')
ck(hashlib.sha256((v/'Core.dll').read_bytes()).hexdigest()
   ==m['original_v432_Core_DLL_SHA256']=='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
   'original genuine v432 Core.dll byte identity')
source=next((ROOT/p['path']).read_text('utf-8') for p in m['original_469f_native_output_source_pins'] if p['path'].endswith('.cpp'))
ck('G3T073469fOriginalNativeClassPropertyV61V62' in source,
   'source original 469f distinct observer GPackage proves not v432')
real=next((ROOT/p['path']).read_text('latin1') for p in m['original_469f_native_output_source_pins'] if 'native.log' in p['path'])
a=(EV/'20261101-G3-T065-T071-T072-T075-original-stock-v432-UClass-v61-v62-four-native-methods.log').read_text('latin1')
b=(EV/'20261101-G3-T065-T071-T072-T075-original-stock-v432-UProperty-v61-v62-two-native-methods.log').read_text('latin1')
cp=r'G3_T065_ORIGINAL_V432_UClass_VER=(\d+) CLASS=(\d+) NAMES=(\d+) REFS=(\d+) BYTES=(\d+) CALLS=(\d+)'
pp=r'G3_T065_ORIGINAL_V432_UProperty_VER=(\d+) BEFORE=([0-9A-Fa-f]+) AFTER=([0-9A-Fa-f]+) NAMES=(\d+) REFS=(\d+) BYTES=(\d+) CALLS=(\d+)'
classes=sorted(set(re.findall(cp,real)));props=sorted(set(re.findall(pp,real)))
ck(classes==sorted(set(re.findall(cp,a))) and len(classes)==4,'4 genuine v432 versus 469f original live class result records identical')
ck(props==sorted(set(re.findall(pp,b))) and len(props)==2,'2 genuine v432 versus 469f original property records identical')
ck('G3_T065_ORIGINAL_V432_6_NATIVE_ORACLES=6' in real,'real 469f originally compiled observer six positive source methods')
for cls in ('0','1'):
 for version,names,refs,raw,calls in (('61','2','7','66','15'),('62','3','8','62','14')):
  ck((version,cls,names,refs,raw,calls) in classes,'actual compiled original 469f class '+cls+' version '+version)
for version,after in (('61','00000003'),('62','00080043')):
 ck(any(v[0]==version and v[1]=='00080043' and v[2]==after for v in props),
    'actual compiled 469f original property flags '+version)
ck('G3_T065_ORIGINAL_V432_Engine_LOADED_PTR=' in real
   and 'G3_T065_ORIGINAL_V432_UProperty_OWNER_PTR=' in real,
   'original compiled 469f actually loaded Engine.u Actor class and real reflected property')
ck(not m['full_T073_acceptance'].startswith('PASS'),'full 469f source type/method owner must stay blocked')
out={'ok':not issues,'checks':n,'errors':issues,'original_v432_vs_469f_same_numerical_archive_method_results':6,
     'source_method_profiles':['stock UT99 v432 Core.dll','stock OldUnreal 469f Core.dll'],
     'original_native_executions_by_verifier':0,'new_actual_original_469f_cases':6,
     'T073_full_owner':'BLOCKED until remaining method/type branch source gaps resolved'}
(EV/'20261101-G3-T073-469f-v432-six-original-methods-verified.json').write_text(json.dumps(out,indent=2)+'\n')
print(json.dumps(out,indent=2))
sys.exit(0 if not issues else 1)
