from pathlib import Path
import json,re
root=Path(r'C:\Temp\UnrealDB\O06')
log=(root/'G3-T052-original-469f-shipped-Core-script-native.log').read_text(encoding='utf-16')
rows=json.loads((root/'G3-T052-current-original-469f-selected-reader.json').read_text(encoding='utf-16'))['rows']
records={}
for m in re.finditer(r'G3_T052_GENUINE_469F_SCRIPT full=([^\r\n]+?) len=(\d+) native_hex=([0-9A-Fa-f]+)',log):
 n=m.group(1);records[n]={'size':int(m.group(2)),'bytes':bytes.fromhex(m.group(3))}
nativePointers={}
for m in re.finditer(r'G3_T052_GENUINE_469F_RUNTIME_POINTER fn=([^\r\n]+?) offset=(\d+) target=([^\r\n]+)',log):
 nativePointers.setdefault(m.group(1),[]).append({'offset':int(m.group(2)),'target':m.group(3)})
out=[]
for row in rows:
 name=row['fullname']
 if name not in records:continue
 raw=records[name]['bytes'];mask=bytes.fromhex(row['mask_hex']);source=bytes.fromhex(row['memory_hex'])
 assert len(raw)==len(source)==len(mask)==row['size'],(name,len(raw),len(source),len(mask),row['size'])
 bad=[{'at':i,'native':raw[i],'source':source[i]} for i,b in enumerate(mask) if b and raw[i]!=source[i]]
 rel=row['relocations'];nref=nativePointers.get(name,[])
 valid=0;missing=[]
 for ref in rel:
  if ref['kind']!='object_pointer':continue
  off=ref['memory_offset'];target=ref.get('target_name')
  ok=[a for a in nref if a['offset']==off and a['target'].split('.')[-1]==target]
  if ok:valid+=1
  else:missing.append({'offset':off,'expected':target,'native_uobject_pointer_matches_at_offset':[a for a in nref if a['offset']==off]})
 out.append({'full':name,'i':row['i'],'size':len(raw),'known':sum(mask),'relocations':len(rel),'object_relocations':sum(x['kind']=='object_pointer' for x in rel),
             'real_native_object_pointer_matches':valid,'real_native_object_pointer_misses':missing,'known_mismatches':bad,'archive_bytes':row['physical_archive_bytes'],'raw_sha256':__import__('hashlib').sha256(raw).hexdigest()})
print(json.dumps({'original_469f_x86_loaded_function_samples':len(records),'reader_sealed_selected_functions':len(rows),'compared':len(out),'total_script_bytes':sum(x['size'] for x in out),'matched_known_bytes':sum(x['known'] for x in out),'mismatch_cases':len([x for x in out if x['known_mismatches']]),'original_native_actual_object_pointer_matches':sum(x['real_native_object_pointer_matches'] for x in out),'original_native_pointer_misses':sum(len(x['real_native_object_pointer_misses']) for x in out),'examples_failed':[(x['full'],x['known_mismatches'][:2],x['real_native_object_pointer_misses'][:2]) for x in out if x['known_mismatches'] or x['real_native_object_pointer_misses']][:7]},indent=2))
(root/'G3-T052-new-original-469f-real-runtime-current-native-comparison.json').write_text(json.dumps({'summary':{'samples':len(out),'known_bytes':sum(x['known'] for x in out),'known_mismatches':sum(len(x['known_mismatches']) for x in out),'typed_pointer_matches':sum(x['real_native_object_pointer_matches'] for x in out)},'rows':out},indent=2)+'\n',encoding='utf-8')
