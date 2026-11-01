from pathlib import Path
import subprocess,json,re,collections,hashlib,os
r=Path(__file__).resolve().parents[2]
manifest=json.loads((r/'catalog/compliance/evidence/o06/20261101-G3-T072-1998-Engine-31-Const-Enum-final-closure.json').read_text(encoding='utf8'))
for key,pin in manifest['pins'].items():
    if key=='source_native_verifier':continue
    path=r/pin['path'] if pin['location']=='repo' else Path(pin['path'])
    if not path.is_file():
        raise RuntimeError('Original native evidence file missing: '+key)
    actual_sha=hashlib.sha256(path.read_bytes()).hexdigest()
    original=path.stat().st_size==pin['bytes'] and actual_sha==pin['sha256']
    normalized=path.stat().st_size==pin.get('canonical_git_blob_bytes') and actual_sha==pin.get('canonical_git_blob_sha256')
    if not (original or normalized):
        raise RuntimeError('Original native evidence SHA changed: '+key)
engine=manifest['pins']['original_stock_Engine_u']['path']
import os
env=os.environ.copy()
env['G3_C98_ORIGINAL_ENGINE_PACKAGE']=engine
evdir=r/'catalog/compliance/evidence/o06'
p=subprocess.run([env.get('PHP_CLI') or r'D:\php8.5\php.exe',str(r/'catalog/bin/verify-ue1-o06-g3-t072-c98-Engine-31-uncovered-Const-Enum-reader.php')],capture_output=True,timeout=20,env=env)
assert p.returncode==0,(p.stdout[:500],p.stderr[:500])
saved=json.loads(p.stdout)
exps={int(k):v for k,v in saved['records'].items()}
elog=(evdir/'20261101-G3-T072-original-1998-Engine-31-only-g3-t072-C98-Engine-uncovered-native-global-FName-enum.log').read_bytes().decode('latin1','replace').splitlines()
clog=(evdir/'20261101-G3-T072-original-1998-Engine-31-only-g3-t072-C98-original-Engine-uncovered-31-Const-Enum-fixed-ABI.log').read_bytes().decode('latin1','replace').splitlines()
eRecords={};nativeNames=collections.defaultdict(dict)
for ln in elog:
 m=re.match(r'^T072_NATIVE_ENUM idx=(\d+) linker=([0-9a-f]{8}) count=(\d+)$',ln)
 if m and m.group(2)!='02db4f80':
  eRecords[int(m.group(1))]=int(m.group(3))
 m=re.match(r'^T072_NATIVE_ENUM_NAME idx=(\d+) ord=(\d+) global=(\d+): [0-9a-f]{8}  "(.*)"$',ln)
 if m and int(m.group(1)) in exps and exps[int(m.group(1))]['kind']=='Enum':
  nativeNames[int(m.group(1))][int(m.group(2))]=m.group(4)
cRecords={}
for i,ln in enumerate(clog):
 m=re.match(r'^T072_CONST_ORIGINAL linker=([0-9a-f]{8}) idx=(\d+) num=(\d+) max=(-?\d+) ptr=([0-9a-f]{8})$',ln)
 if m and int(m.group(2)) in exps:
  value=re.search(r'^[0-9a-f]{8}\s+"(.*)"$',clog[i+1] if i+1<len(clog) else '')
  cRecords[int(m.group(2))]={'num':int(m.group(3)),'text':value.group(1) if value else None}
errors=[];values=0;nameCount=0;summary={}
for id,v in exps.items():
 if v['kind']=='Enum':
  if id not in eRecords:errors.append(f'Missing original compiled Enum export={id}');continue
  if len(nativeNames[id])!=eRecords[id]:errors.append(f'Native enum global FName text cardinality id={id}: {len(nativeNames[id])}/{eRecords[id]}')
  if eRecords[id]!=v['name_count']:errors.append(f'Enum count id={id}: native={eRecords[id]} vs file={v["name_count"]}')
  if len(v['names'])!=eRecords[id]:errors.append(f'Enum array length id={id}')
  for i,n in enumerate(v['names']):
   if nativeNames[id].get(i)!=n:
    errors.append(f'Enum id={id} ordinal={i} original={nativeNames[id].get(i)!r} saved={n!r}')
   else:nameCount+=1
  if v['extension_bytes']!=0 or v['status']!='native_enum_record_complete':errors.append(f'Enum archive incomplete id={id}')
 else:
  if id not in cRecords:errors.append(f'Missing original compiled Const export={id}');continue
  a=cRecords[id]
  if a['num']!=v['value_count']:errors.append(f'Const archive count id={id}: {a["num"]}/{v["value_count"]}')
  if a['text']!=v['value']:errors.append(f'Const loaded text id={id}: {a["text"]!r}/{v["value"]!r}')
  if a['text']==v['value']:values+=1
  if v['extension_bytes']!=0 or v['status']!='native_const_record_complete':errors.append(f'Const archive incomplete id={id}')
summary={'real_Engine_types':dict(collections.Counter(v['kind'] for v in exps.values())),'original_engine_Enum_method_callbacks':len(eRecords),
 'native_enum_global_FName_names':sum(len(v) for v in nativeNames.values()),'exact_saved_enum_name_matches':nameCount,
 'original_Engine_Const_method_callbacks':len(cRecords),'exact_native_Const_strings':values,'errors':errors[:22]}
print(json.dumps(summary,indent=2))
out=None
pass
if errors:raise SystemExit(1)
