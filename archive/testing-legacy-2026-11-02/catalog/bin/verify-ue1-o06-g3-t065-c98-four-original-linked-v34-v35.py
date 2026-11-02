#!/usr/bin/env python3
"""Verify original C98 1998 linked-core v34/35 UClass method source proof.
Does not rerun the original executable, does not claim genuine v34 file loading.
"""
from pathlib import Path
import hashlib,json,re,struct,sys
root=Path(__file__).resolve().parents[2]
ev=root/'catalog/compliance/evidence/o06'
m=json.loads((ev/'20261101-G3-T065-original-C98-four-linked-v34-v35-native-methods-partial.json').read_text())
errors=[];count=0
def ck(ok,msg):
 global count
 count+=1
 if not ok:errors.append(msg)
ck(m['fixed_owner_ids']==['T065','T072','T075','T077'] and not m['full_frozen_T065_accepted'],'frozen T065 not prematurely promoted')
ck(m['native_actual_returned_method_cases']==4 and m['compiled_method_actual_archive_versions']==[34,35],'four distinct real linked class method calls')
ck(m['original_FArchive_method_driver_not_real_retail_v34_package'],'controlled old version archive is not original v34 retail package')
ck(m['host_abnormal_exit_after_completed_four_methods'],'original host failure recorded after positive method observations')
for p in m['new_native_script_and_stdout_pins']:
 path=root/p['path']
 ck(path.is_file() and path.stat().st_size==p['bytes'] and hashlib.sha256(path.read_bytes()).hexdigest()==p['sha256'],'actual original native source/log checksum '+p['path'])
original=Path(r'L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnClass.cpp')
ck(hashlib.sha256(original.read_bytes()).hexdigest()==m['original_C98_1998_UnClass_cpp_SHA256'],'pinned original 1998 source')
src=original.read_text(encoding='latin1')
ck('if( Ar.Ver() < 57 )//oldver' in src,'UClass pre-v57 original two FName fields source gate')
dll=Path(r'C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System\Core.dll')
pkg=dll.with_suffix('.u')
ck(hashlib.sha256(dll.read_bytes()).hexdigest()==m['original_Core_DLL_SHA256'],'original compiled C98 Core DLL immutable')
ck(hashlib.sha256(pkg.read_bytes()).hexdigest()==m['original_Core_u_SHA256'],'original C98 Core package immutable')
ck(struct.unpack_from('<I',pkg.read_bytes(),4)[0]==61 and m['genuine_orig_core_u_archive_version']==61,'original physically linked class source package v61, not fabricated v34')
log=next((root/x['path']).read_text('latin1') for x in m['new_native_script_and_stdout_pins'] if x['path'].endswith('native.log'))
classes=re.findall(r'G3_C98_REAL_CLASS_LINKED index=(\d+) class=(\w+) ptr=([0-9A-F]{8}) linker=([0-9A-F]{8}) export=(\d+)',log)
ck(len(classes)==2 and [x[1] for x in classes]==['Subsystem','Object'],'two actual original linked classes')
for c in classes:
 ck(int(c[2],16)>0 and int(c[3],16)>0,'non-null original C98 live class/linker pointer '+c[1])
ck([x[4] for x in classes]==['229','0'],'genuine original linker export identities class object')
records=re.findall(r'G3_C98_NATIVE_UCLASS CLASS=(\d+) VER=(\d+) NAMES=(\d+) REFS=(\d+) RAW=(\d+) CURSOR=(\d+)',log)
expected=[('0','34','7','12','102','121'),('0','35','7','12','102','121'),
          ('1','34','7','11','94','112'),('1','35','7','11','94','112')]
ck(records==expected and m['native_original_object_primitive_results']==[list(x) for x in expected],
   'actual original eight live class/filename index physical method counters exact four new cases')
ck('G3_T065_ORIGINAL_1998_C98_LINKED_v34_v35_DONE' in log,'all four native original method calls returned')
ck('G3_C98_NATIVE_UCLASS_EXCEPTION' not in log and 'G3_C98_NATIVE_UCLASS_SOURCE_THROW' not in log,'no original native Linker assertion in this controlled run')
ck('G3_C98_ACTUAL_RETAIL_LINKED_CLASS_COUNT=2' in log and m['native_original_Core_u_actual_linker_provenance'],'genuine linked class prerequisite source selected')
result={'ok':not errors,'checks':count,'errors':errors,
 'new_original_1998_compiled_C98_linked_class_method_cases':4,
 'original_native_executions_by_verifier':0,
 'current_frozen_T065':'BLOCKED: method transacting archive gate proven but genuine retail v34 binary and all source branches unverified',
 'source_and_binary_unchanged':True}
(ev/'20261101-G3-T065-original-C98-four-linked-v34-v35-native-methods-verified.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
sys.exit(0 if not errors else 1)
