#!/usr/bin/env python3
"""Verify new C227i 2012 versus C227k 2026 original shipped source/BMP results.
Read-only verifier: never reruns any native engine commandlet.
"""
from pathlib import Path
import json,hashlib,re,sys
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
m=json.loads((EV/'20261101-G3-T065-T071-T074-12-original-227i-k-shipped-native-export-cases.json').read_text())
errors=[];n=0
def ck(ok,msg):
 global n
 n+=1
 if not ok:errors.append(msg)
ck(m['native_orig_2012_2026_true_new_build_sessions']==10 and m['FROZEN_TESTS_PROMOTED']==0,'10 new native cases no whole gate promotion')
ck(m['current_original_native_2012_2026_BMP_positive_sessions']==8
   and m['original_native_2012_2026_BMP_failure_sessions']==2,'eight originals succeeded two genuine GPF negatives')
cases=m['new_original_compiled_UCC_cases']
ck(len(cases)==10 and set((x['build'],x['catalog_id']) for x in cases)=={
 (b,i) for b in ('2012-C227i','2026-C227k') for i in (113274,1256343,1258823,1276716,148267)},
 'exact five physically distinct genuinely shipped providers in both original engine builds')
expected={113274:37,1256343:4,1258823:4,1276716:11,148267:0}
for x in cases:
 log=x['native_output'];p=ROOT/log['path'];payload=p.read_bytes()
 ck(hashlib.sha256(payload).hexdigest()==log['sha256'] and len(payload)==log['bytes'],
    'unchanged actual original compiled engine commandlet log '+log['path'])
 ex=expected[x['catalog_id']]
 ck(x['BMP_written_original']==ex,'actual native original bitmap count for genuine shipped package '+str(x['catalog_id']))
 ck(x['original_native_exit']==(1 if x['catalog_id']==148267 else 0),
    'actual original engine UCC rc per genuine shipped archive '+str(x['catalog_id']))
 t=payload.decode('latin1',errors='replace')
 if x['catalog_id']==148267:
  ck('General protection fault!' in t and 'UTextureExporterBMP::ExportBinary' in t,
     'exact original source native BMP exporter fault '+x['build'])
  ck('Loading package Detail.utx...' in t,'actual original binary engine entered source loader '+x['build'])
 else:
  lines=re.findall(r'Exported (?:Texture|WaveTexture) Detail\.[^\r\n]*\.bmp',t)
  ck(len(lines)==ex,'actual positive original 2012/2026 native export events '+x['build']+' '+str(x['catalog_id']))
  ck('General protection fault!' not in t,'no original native compiled engine exception for accepted package '+str(x['catalog_id']))
diagnostics=m['additional_native_export_error_diagnostic_cases']
ck(len(diagnostics)==2,'two separate original source classification error-method followup contexts')
for d in diagnostics:
 log=d['log'];p=ROOT/log['path']
 ck(p.exists() and hashlib.sha256(p.read_bytes()).hexdigest()==log['sha256'],
    'original native alternative evidence immutability '+d['build'])
 ck(d['native_exit']==0,'original built-in alternate commandlet successful '+d['build'])
 if d['native_method']=='listobjects':
  lines=p.read_text(encoding='latin1',errors='replace')
  ck('Texture       stones' in lines and 'WaveTexture   WaterDE2' in lines and 'Texture       metalf3' in lines,
     'original 2026 source binary actually enumerated genuine failed BMP archive class objects')
 else:
  ck(d['native_method']=='checksumpackage' and log['bytes']==0,
     '2012 checksumpackage silent success NOT erroneously claimed to prove loaded objects')
pairs=m['original_2012_2026_native_BMP_bytewise_matched_SHA_pairs']
ck(len(pairs)==56 and {x['catalog_id'] for x in pairs}=={113274,1256343,1258823,1276716},
    'fifty-six original native generated BMP comparisons, no invented data for failing original')
for row in pairs:
 a=Path(row['original_2012_native_output_private_path'])
 b=Path(row['original_2026_native_output_private_path'])
 sha=row['original_2012_native_BMP_SHA256']
 ck(a.is_file() and b.is_file() and a.read_bytes()==b.read_bytes(),'actual two distinct original compiled engine BMP bytes match '+row['name'])
 ck(hashlib.sha256(a.read_bytes()).hexdigest()==sha==hashlib.sha256(b.read_bytes()).hexdigest()
    and len(a.read_bytes())==row['bytes'],'source original 2012/2026 BMP same hash and physical size '+row['name'])
for build,sysfolder,expected in (
 ('2012-C227i',Path(r'C:\Temp\UnrealDB\O06\Native-C227i-G304\System'),
  '287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'),
 ('2026-C227k',Path(r'C:\Temp\UnrealDB\O06\Native-227k\System'),
  'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194')
):
 ck(hashlib.sha256((sysfolder/'Core.dll').read_bytes()).hexdigest()==expected,
    'canonical original compiled source 2012/2026 engine SHA unchanged '+build)
 ck(not (sysfolder.parent/'Textures/Detail.utx').exists(),
    'genuine original shipped file was staged only temporarily and restored '+build)
result={'ok':not errors,'checks':n,'errors':errors,'original_native_executions_by_verifier':0,
 'true_new_original_2012_2026_UCC_batchexport_sessions':10,
 'original_2012_2026_alternate_source_built_in_commandlet_sessions':2,
 'original_positive_archive_BMP_sessions':8,'original_unmodified_executable_BMP_crashes':2,
 'actual_2012_vs_2026_real_generated_BMP_pair_matches':56,
 'source_loaded_unverified_C98_v34_still_blocked':True,
 'full_frozen_T074_status':'BLOCKED: other original 2012/2026 method/type branch distinct source-native parity remains'}
(EV/'20261101-G3-T065-T071-T074-C227i-C227k-56-identical-native-BMP-acceptance.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps(result,indent=2))
sys.exit(0 if not errors else 1)
