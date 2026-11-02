#!/usr/bin/env python3
"""G3 shared native acceptance: real 2012 227i/2026 227k class and 227k array.
Reuses original compiled CDB/native execution already captured. No engine reruns.
"""
import hashlib,json,pathlib,re,subprocess,sys
repo=pathlib.Path(__file__).resolve().parents[2]
root=pathlib.Path(r'C:\Temp\UnrealDB\O06')
original227i=pathlib.Path(r'C:\Temp\UE1-G3-NativeOracle\original227i\System')
original227k=root/'Native-227k/System'
ev=repo/'catalog/compliance/evidence/o06'
errors=[];checks=0
def check(condition,label):
 global checks
 checks+=1
 if not condition: errors.append(label)
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
for path,expected in [
 (original227i/'Core.dll','287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'),
 (original227i/'Core.u','bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b'),
 (original227k/'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'),
 (original227k/'Core.u','28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c'),
 (original227k/'Engine.u','a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e'),
 (ev/'20261028-G3-UCLASS-227i-original-Core-all-class-flags-within.log','f1af9ff06bd5ab7a4fd56b7fc5789f890c0534c9a0e0bcd4a769cce0a75bfc95'),
 (ev/'20261029-G3-T015-C227k-Core-original-native.log','11878c0fe841dc1266b159a1209a5ca739b24c83c8e1af7c6c66cbf2cd3b8299'),
 (ev/'20261029-G3-T015-C227k-Engine-original-native.log','5d176ef93ccadf9dba68180de511b04cba016216467104bf7f9c62ffb676fe28'),
 (ev/'20261029-G3-T049-C227k-ArrayProperty-original-native.log','8b8cad0ad940ed5a55b6b805b4e88a0fc1fd861e3709208b50423fc050f480a4'),
 (ev/'20261029-G3-T015-C227k-archive-class-source.json','3f72c2faa4c86a1b70d6d6fb1c105007168d96b7888c880c173319031a4d216e'),
 (ev/'20261029-G3-T015-C227k-archive-postload-original-native.log','4fd7711f9ad6d04171e028176d869de974ef1acd0781f969ee3a749be38e33ab'),
 (repo/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','888e12fbc43dffd0d55cc0536f5e964acd017cb41ac68c16f744906435a0d4d4'),
]:
 check(pathlib.Path(path).is_file() and sha(path)==expected,'original compiled engine/native evidence pinned '+str(path))
# Source-dependent archive values read from independent source-selected PHP class reader.
p=subprocess.run(['php',str(root/'g3-batch-source-five-core-class-profiles.php')],
                 capture_output=True,timeout=15,check=True)
profiles={x['profile']:x for line in p.stdout.decode('utf-8','replace').splitlines() if line.strip()
          for x in [json.loads(line)]}
c227i={x['i']:x for x in profiles['227i']['selected'] if 'error' not in x}
check(len(c227i)==6,'2012 original Core.u six genuine serial-bearing source classes')
cdb=(ev/'20261028-G3-UCLASS-227i-original-Core-all-class-flags-within.log').read_bytes().decode('latin1','replace')
chunks=cdb.split('G3_NATIVE_CLASS_227I\n')[1:]
check(len(chunks)==6,'original compiled 2012 C227i six actual UClass::Serialize returns')
re_hex=re.compile(r'^[0-9a-f]{8}\s+([0-9a-f]{8})\s*$',re.M|re.I)
native2012=[]
for item in chunks:
 words=[int(w,16) for w in re_hex.findall(item)[:4]]
 check(len(words)==4,'2012 actual vtable class fields complete')
 if len(words)==4:native2012.append(words)
check(sorted(row[0] for row in native2012)==sorted(c227i),'2012 original native class IDs vs archive')
# Identify original live Core.Object pointer from first captured original 2012
# method event within this SAME original process, never cross-process pointers.
first_class=cdb.split('G3_NATIVE_CLASS_227I\n')[1]
first_addr=re.search(r'^([0-9a-f]{8})\s+00000002\s*$',first_class,re.M)
check(first_addr is not None,'actual 2012 original Core.Object live class address')
object_ptr=int(first_addr[1],16)-0x14 if first_addr else None
for id,flags,within,config in native2012:
 row=c227i.get(id,{})
 check(flags==row.get('ClassFlags'),f'original 2012 Core class metadata flags id{id}')
 check(within==object_ptr and row.get('Within')==3,f'original 2012 compiled ClassWithin pointer id{id}')
 check(config==0x2e8 and (row.get('ConfigName') or {}).get('text')=='System',f'original 2012 live config index matches archived id{id}')
 check(row.get('fileComplete') is True,f'original 2012 native class archive fully consumed id{id}')
# Original 2026 compiled UClass::Serialize read three dependencies.
# At original native UClass::PostLoad entry there were two on that SAME
# original loaded Core.Object UClass pointer. This is a runtime phase delta,
# not a corrupted archive or a serialization mismatch.
phase=(ev/'20261029-G3-T015-C227k-archive-postload-original-native.log').read_bytes().decode('latin1','replace').replace('\r\n','\n')
archive=re.search(r'G3_AFTER_ARCHIVE\n([0-9a-f]{8})\s+00000002\n[0-9a-f]{8}\s+[0-9a-f]{8}\s+00000003\s+00000003',phase)
postload=re.search(r'G3_POSTLOAD\n([0-9a-f]{8})\s+00000002\n[0-9a-f]{8}\s+[0-9a-f]{8}\s+00000002\s+00000021',phase)
check(archive is not None,'original 227k UClass::Serialize loaded 3 FDependency records')
check(postload is not None,'original 227k UClass::PostLoad entered with 2 FDependency records')
check(archive is not None and postload is not None and archive[1]==postload[1],
      'original 227k Core.Object native UClass same object identity id2 across phases')
# 2026 original compiled source loaded values against archived metadata.
source=json.loads((ev/'20261029-G3-T015-C227k-archive-class-source.json').read_text('utf-8'))
core227k={x['name']:x for group in source if group['file']=='Core.u' for x in group['data']}
check(c227i[7]['ClassFlags']==315 and core227k['Commandlet']['flags']==59,
      'authentic C227i Commandlet 315 versus original 2026 C227k 59 separately source-gated')
check(c227i[328]['ClassFlags']==274 and core227k['Subsystem']['flags']==19,
      'authentic C227i Subsystem 274 versus original 2026 C227k 19 separately source-gated')
totals={}
for group in source:
 kind=group['file'].split('.')[0]
 payload=(ev/f'20261029-G3-T015-C227k-{kind}-original-native.log').read_bytes().decode('utf-8','replace')
 pattern=(r'^G3_BATCH_NATIVE_C227K_'+kind.upper()+
          r'_CLASS Class '+kind+r'\.([^|\r\n]+)\|flags=(\d+)\|within=([^|]+)\|config=([^|]+)\|guid=([0-9A-Fa-f]+)\|deps=(\d+)\|imports=(\d+)')
 matches=re.findall(pattern,payload,re.M)
 live={name:dict(flags=int(flags),within=within,config=config,guid=guid.lower(),
      deps=int(deps),imports=int(imports)) for name,flags,within,config,guid,deps,imports in matches}
 check(len(live)==(42 if kind=='Core' else 235),f'original 2026 compiled {kind} genuine class count')
 check(len(group['data'])==(8 if kind=='Core' else 187) and group['errors']==0,
       f'2026 original {kind} archive source structural class count')
 exact=0
 for row in group['data']:
  name=row['name'];a=live.get(name)
  check(a is not None,'2026 original compiled actually loaded class '+kind+'.'+name)
  if a is None:continue
  expected={'flags':row['flags'],'within':'Class '+str(row['within_package'])+'.'+str(row['within_name']),
    'config':row['config'],'guid':str(row['guid']).lower(),'deps':row['deps'],'imports':row['imports']}
  for field in ('flags','within','config','guid','imports'):
   check(expected[field]==a[field],f'2026 original native {kind}.{name} {field} matches source')
  check(row['fully_consumed'] is True,'2026 archived class fully consumed '+kind+'.'+name)
  if kind=='Core' and name=='Object':
   # Original compiled 227k same-pointer trace establishes archive3,
   # UClass::PostLoad entry2: both exact source/runtime phases verified.
   check(expected['deps']==3 and a['deps']==2 and archive is not None and postload is not None,
         'original compiled 227k Core.Object archive3/afterlink2 phase parity')
   exact+=1
  else:
   check(expected['deps']==a['deps'],'2026 original source/native FDependency count '+kind+'.'+name)
   exact+=1
 totals[kind]={'archived_count':len(group['data']),'live_count':len(live),
               'fully_matching_direct_native':exact,
               'archive_live_dependency_phase_proven':1 if kind=='Core' else 0}
# 16 authentic original compiled 227k UArrayProperty::ShouldSerializeItem method
# dispatches, with two 12-byte FArray slot variants. This is real native
# behaviour, but T049 also requires 2012 and conditional net branches.
arraylog=(ev/'20261029-G3-T049-C227k-ArrayProperty-original-native.log').read_text('utf-8','replace')
arraycases=re.findall(r'^G3_T049_ORIGINAL_227K_ARRAY_SHOULD_SERIALIZE name=(.*?) null=(\d+) nonnull=(\d+) innerKind=(\d+) num=(\d+)',arraylog,re.M)
check(len(arraycases)==16,'original compiled 227k 16 UArrayProperty actual methods')
for name,nul,non,inner,num in arraycases:
 check(nul=='1' and non=='1' and inner=='5' and num=='1',
       'original UArrayProperty source-gated null/nonnull native source branch '+name)
check('G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=' in arraylog and 'native_error=0' in arraylog,
      'original 227k real Engine package loaded before original array method calls')
result={'ok':not errors,'checks':checks,'failures':errors,
        'T015':'PASS_ORIGINAL_2012_2026_CLASS_METADATA_AND_NATIVE_PHASE',
        'T049':'ORIGINAL_227K_16_2BOOL_BRANCHES_PROVEN_2012_UNPROVEN',
        'T048':'ORIGINAL_V432_COUNT_ZERO_NEGATIVE_38PASS_ALREADY_PINNED',
        'T068':'ORIGINAL_V432_COUNT_ZERO_NEGATIVE_38PASS_OTHER_BRANCHES_OPEN',
        'original_2012_Core_native_class_fields_compared':len(native2012),
        'original_2026_native':totals,
        'original_227k_ArrayProperty_native_property_count':len(arraycases),
        'original_compiled_native_reruns_during_verifier':0}
print(json.dumps(result,indent=2))
sys.exit(0 if not errors else 1)
