#!/usr/bin/env python3
from pathlib import Path
import hashlib,json,re,subprocess,sys
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
m=json.loads((EV/'20261031-G3-T076-stock-v432-8-original-native-direct-and-real-import-provider-partial.json').read_text())
old=json.loads((EV/'20261031-G3-T065-original-C98-v34-v35-runtime-probe-blocked-no-map.json').read_text())
errors=[];checks=0
def ck(ok,msg):
 global checks
 checks+=1
 if not ok:errors.append(msg)
ck(m['T076_frozen_status']=='BLOCKED' and m['new_authentic_original_stock_native_executions']==8,'all builds not yet completed')
ck(m['original_stock_UCC_real_consumer_has_true_imported_UClass_superfield'],'real original superclass import')
ck(m['no_original_shipped_same-name_provider_fixture_verified'],'test compiler cases not real shipped duplicate')
for k,v in m['source_immutable_binary_and_fixtures_only_on_DEV_scratch'].items():
 p=Path(v['DEV_private_path'])
 ck(p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==v['sha256'],'native original SHA '+k)
 ck(p.is_file() and p.stat().st_size==v['bytes'],'native original size '+k)
for row in m['retained_original_compiled_native_stdout_and_user_authored_source_SHA']:
 p=ROOT/row['path']
 ck(p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==row['sha256'],'retained original stdout SHA '+p.name)
 ck(p.is_file() and p.stat().st_size==row['bytes'],'retained stdout size '+p.name)
for mode,items,expect,marker in (
 ('direct',m['original_v432_native_direct_package_choice'],m['original_stock_cpp_direct_provider_results'],'G3_T076_ORIGINAL_COMPILED_PROVIDER_SELECTION'),
 ('imported-superclass',m['original_v432_native_true_linker_superclass_import_choice'],m['original_stock_cpp_imported_class_resolved_default_sentinel_cases'],'G3_T076_NATIVE_TRUE_IMPORTED_PROVIDER')):
 ck(len(items)==4,'four valid original engine cases '+mode)
 for row in items:
  label=row['case'];want=expect[label]
  p=EV/('20261031-G3-T076-stock-v432-'+mode+'-'+label+'.log')
  txt=p.read_text(encoding='latin1',errors='replace')
  rc=row.get('return_code',row.get('actual_original_native_exit'))
  ck(rc==(7 if want is None else 0),'actual original compiled loader exit '+mode+label)
  ck((marker in txt)==(want is not None),'actual real source class loaded '+mode+label)
  if want is not None:
   ck(re.search(r'\bsentinel='+str(want)+r'\b',txt) is not None,'real native source default value '+mode+label)
   if mode=='imported-superclass':
    ck(re.search(r'\bsuper=[0-9A-Fa-f]{8}',txt) is not None
      and re.search(r'\bcdo=[0-9A-Fa-f]{8}',txt) is not None,
      'original imported UClass pointer and default memory '+label)
src=(ROOT/'catalog/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php').read_text(encoding='utf-8')
ck('if (count($candidates) > 1)' in src and "'selection_status' => 'ambiguous'" in src,
   'current indexed provider intentionally ambiguous without mount search context')
sy=Path(r'C:\Temp\UnrealDB\O06\Native-UCC\System')
ini=(sy/'UnrealTournament.ini').read_bytes()
ck(ini.find(b'Paths=../System/*.u')>=0
   and ini.find(b'Paths=../Textures/*.utx')>ini.find(b'Paths=../System/*.u'),
   'stock v432 original path priority System then Textures')
for path in (sy/'G3T076Dupe.u',sy/'G3T076Consumer.u',Path(r'C:\Temp\UnrealDB\O06\Native-UCC\Textures\G3T076Dupe.utx')):
 ck(not path.exists(),'original engine test files removed from live original searchPaths '+path.name)
proc=subprocess.run([r'D:\php8.5\php.exe',str(ROOT/'catalog/bin/verify-ue1-o06-g3-t076-original-v432-real-imported-duplicate-archive.php')],cwd=ROOT,capture_output=True,timeout=23)
ck(proc.returncode==0,'current production archive parser PHP current source')
p=json.loads(proc.stdout.decode('utf-8'))
ck(p['ok'] and p['checks']==7 and p['archive_loaded_superclass_record']==-3,
   'genuine original UCC-produced import reference and two provider default archive values')
ck(old['T065_frozen_status']=='BLOCKED'
   and len(old['original_C98_1998_v35_real_DProj_utx_and_v34_header_derivative_attempts'])==2
   and all(x['exit']==124 for x in old['original_C98_1998_v35_real_DProj_utx_and_v34_header_derivative_attempts']),
   'both true original 1998 C98 attempts blocked before v34 source field')
ck(old['source_C98_original_native_executable_loaded_DProj'] is False,
   'no unsound C98 v34 acceptance from missing map startup')
o={'ok':not errors,'checks':checks,'errors':errors,'stock_v432_direct_original_native_sessions':4,
'stock_v432_true_imported_UClass_native_sessions':4,'genuine_original_stock_UCC_compiled_packages':3,
'current_UE1_reader_archived_tag_and_import_reference_checks':p['checks'],
'original_native_engine_executions_by_this_verifier':0,
'T076_status':'BLOCKED: shipped duplicate provenance and indexed provider search order missing',
'T065_status':'BLOCKED: original 1998 v34/v35 real archive method not reached; missing startup Unreal.unr',
'no_accepted_original_native_retests':True}
(EV/'20261031-G3-T076-original-stock-8-native-real-duplicate-import-source-acceptance.json').write_text(json.dumps(o,indent=2)+'\n')
print(json.dumps(o,indent=2))
sys.exit(0 if errors else 1)
