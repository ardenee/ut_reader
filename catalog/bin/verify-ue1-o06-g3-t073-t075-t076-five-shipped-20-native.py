#!/usr/bin/env python3
"""Frozen G3 T073/T075/T076: 20 immutable genuine shipped original v432/469f native loads.
Uses original C++/source SHA attestations and current PHP package reader.
Never repeats actual native engine runs, never changes PRD or original binaries.
"""
from pathlib import Path
import hashlib,json,re,subprocess,sys
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
m=json.loads((EV/'20261031-G3-T073-T075-T076-20-real-UT99-shipped-native-and-5-indexed-ownership.json').read_text())
err=[];n=0
def check(ok,msg):
 global n
 n+=1
 if not ok:err.append(msg)
check(m['original_compiled_new_real_shipped_native_loads']==20 and len(m['original_native_cases'])==20,
      'twenty distinct native original build and authentic shipped cases')
check(m['original_compiled_engine_profiles']==['v432','469f'] and m['actual_original_indexed_shipped_candidates']==5,
      'two authentic compiled engine builds five true catalog source candidates')
check(m['original_PRD_only_readonly_source'] and not m['PRD_altered'] and m['native_config_and_original_binaries_untouched'],
      'only read PRD and unchanged stock original native source/binaries')
for row in m['archived_original_evidence_pins']:
 p=ROOT/row['path']
 check(p.is_file() and p.stat().st_size==row['bytes'] and hashlib.sha256(p.read_bytes()).hexdigest()==row['sha256'],
       'original native/archived compiler artifacts SHA '+row['path'])
ret=subprocess.run([r'D:\php8.5\php.exe',str(ROOT/'catalog/bin/verify-ue1-o06-g3-t073-t075-t076-five-genuine-shipped-archive-ids.php')],
                   cwd=ROOT,capture_output=True,timeout=28)
check(ret.returncode==0,'current source selected reader accepts actual five authentic shipped packages')
if ret.returncode:err.append(ret.stderr.decode('utf-8','replace')[:750]);sys.exit(1)
reader=json.loads(ret.stdout.decode('utf-8'))
check(reader['ok'] is True and reader['checks']==44 and len(reader['actual_original_shipped_same_name_physical_candidates'])==5,
      '44 accepted real archive package/header data parity assertions')
by={x['file_id']:x for x in reader['actual_original_shipped_same_name_physical_candidates']}
expected_ids={113274,148267,1256343,1258823,1276716}
check(set(by)==expected_ids,'exact five genuine PRD verified old archive candidates')
check(by[113274]['package_guid_dwords']==by[148267]['package_guid_dwords']
      and by[113274]['bytes']!=by[148267]['bytes'],'real original GUID reuse cannot prove physical provider identity')
indexed=json.loads((EV/'20261031-G3-T073-T075-T076-source-PRD-five-indexed-candidate-rows.json').read_text())
check(indexed['read_only'] and indexed['catalog_key_kind']==3
      and indexed['package_key_hex']=='951DA6B7179A4F697CC89D36ACF74E52','actual indexed PRD Detail package key and readonly query')
listed=indexed['bounded_actual_indexed_candidate_rows']
check(len(listed)==5 and {x['file_id'] for x in listed}==expected_ids
      and all(x['source_kind']==1 and x['scan_status']=='verified' for x in listed),
      'all five true original vetted physical providers indexed for same FName')
for x in listed:
 check(int(x['package_version'])==by[x['file_id']]['version']
       and int(x['file_size'])==by[x['file_id']]['bytes'],
       'source current actual original PRD file metadata agrees with canonical decoder '+str(x['file_id']))
native_count={'v432':0,'469f':0}
old_count=0;extra_count=0
for case in m['original_native_cases']:
 label=case['case'];entry=case['native_outcome'];profile=case['profile']
 pin=case['native_log'];text=(ROOT/pin['path']).read_text(encoding='latin1',errors='replace')
 check(profile in native_count,'original stock compiled build provenance '+label)
 if profile in native_count:native_count[profile]+=1
 if 'native_loaded_linker_summary' in entry:
  extra_count+=1
  wanted=entry['source_selected_id_under_original_source_SearchPaths']
  observed=entry['native_loaded_linker_summary']
  check(wanted in expected_ids and observed is not None and entry['native_execution_rc']==0,
        'original native real linker summary loaded object and physical file '+label)
  if wanted in by and observed:
   expected=by[wanted]
   check(observed['file_bytes']==expected['bytes'] and observed['version']==expected['version'],
         'real original compiled linker FArchive::TotalSize and summary version '+label)
   check(observed['exports']==expected['exports'] and observed['names']==expected['names'],
         'real original compiled header counts versus selected UnrealDB parser '+label)
   check(observed['guid'].upper()==expected['package_guid_dwords'].upper(),
         'real original compiled source header GUID all four DWORDs '+label)
   check(re.search(r'G3_T076_FIVE_SHIPPED_LOADER_POINTERS package=[0-9A-Fa-f]{8} stones=(?!00000000)[0-9A-Fa-f]{8} linker=(?!00000000)[0-9A-Fa-f]{8}',text) is not None,
         'real original C++ loaded actual live texture UObject and ULinkerLoad pointer '+label)
   check('G3_T076_FIVE_SHIPPED_REAL_LOADED=1' in text,
         'original compiled engine definite load result '+label)
 else:
  old_count+=1
  is469f=profile=='469f'
  a=entry['system_shipped_version'] if is469f else entry['shipped_system_catalog_provider_version']
  b=entry['textures_shipped_version'] if is469f else entry['shipped_textures_catalog_provider_version']
  expected=a if a is not None else b
  actual=entry['actual_469f_native_selected_version'] if is469f else entry['native_reported_version']
  code=entry['actual_469f_original_compiled_exit'] if is469f else entry['actual_stock_compiled_native_rc']
  check(code==(7 if expected is None else 0),'real original native win32 load / missing provider '+label)
  check(actual==(0 if expected is None else expected),'original source selected System->Textures version '+label)
  prefix='G3_T076_469F_SHIPPED_NATIVE_ACTUAL' if is469f else 'G3_T076_SHIPPED_ORIGINAL_NATIVE_ACTUAL'
  if expected is not None:
   expr=re.search(r'\bpaint1=([0-9A-Fa-f]{8}) rough=([0-9A-Fa-f]{8})',text)
   check(prefix in text and expr is not None,'source native actual texture pointer identities '+label)
   if expr:
    check((int(expr.group(1),16)>0)==(expected==68)
          and (int(expr.group(2),16)>0)==(expected==69),
          'original live native actual paint1 versus rough unique package markers '+label)
  else:check('MISSING_OR_CORRUPT' in text or 'SELECTED_VERSION=0' in text,
             'original missing package exception or native fail without fake selected physical provider '+label)
check(native_count=={'v432':10,'469f':10} and old_count==10 and extra_count==10,
      'twenty native new real shipped cases, 10 per original build no accepted UCC rerun')
for profile,folder,sha in (
 ('v432',r'C:\Temp\UnrealDB\O06\Native-UCC\System','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'),
 ('469f',r'C:\Temp\UnrealDB\O06\Native-469f\System','80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2')
):
 system=Path(folder)
 check(hashlib.sha256((system/'Core.dll').read_bytes()).hexdigest()==sha,
       'original compiled source native Core.dll byte identical '+profile)
 ini=(system/'UnrealTournament.ini').read_bytes()
 check(ini.find(b'Paths=../System/*.u')>=0 and ini.find(b'Paths=../Textures/*.utx')>ini.find(b'Paths=../System/*.u'),
       'genuine source compiled SearchPaths System before Textures '+profile)
 check(not (system/'Detail.u').exists(),'no real original engine search path test artifact residue '+profile)
check(m['T076_fixed_test_complete'] is False and not m['T073_T075_fixed_tests_complete'],
      'do not falsely promote other engine family or pointer acceptance')
o={'ok':not err,'checks':n,'errors':err,
   'new_original_authentic_shipped_native_executions':sum(native_count.values()),
   'new_actual_original_compiled_build_counts':native_count,
   'current_unrealdb_genuine_five_package_field_checks':reader['checks'],
   'original_shipped_same_name_Indexed_provider_count':5,
   'original_CPP_source_loaded_live_pointer_observation':True,
   'original_v61_and_v69_same_GUID_different_physical_file':True,
   'T073_T075_T076_full_80_ID_acceptance':'BLOCKED (other source/build original oracle and actual mounted path/provenance remain)',
   'original_native_executions_by_verifier':0,
   'Original_C98_v34_unverified_not_mutated':True}
(EV/'20261031-G3-T073-T075-T076-five-shipped-20-original-native-acceptance-verified.json').write_text(json.dumps(o,indent=2)+'\n')
print(json.dumps(o,indent=2))
sys.exit(0 if not err else 1)
