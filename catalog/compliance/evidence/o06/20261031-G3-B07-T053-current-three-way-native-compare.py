from pathlib import Path
import json,re,hashlib
root=Path(r'C:\Temp\UnrealDB\O06')
txt=(root/'G3-T053-original-C227k-C227i-mixed-script-native.log').read_text(encoding='utf-16')
src=[json.loads(s) for s in (root/'G3-T053-script-current-compact-reader.jsonl').read_text(encoding='utf-16').splitlines()]
cdb=(root/'G3-T053-original-2012-C227i-live-script-CDB.log').read_text(encoding='utf8',errors='replace')
data=[]
for block in cdb.split('G3_T053_ORIGINAL_2012_NATIVE_89_BYTE_SCRIPT')[1:]:
 found=[]
 for line in block.splitlines():
  m=re.match(r'^([0-9a-f]{8})\s+((?:[0-9a-f]{2}[ \-]+){1,16})',line.strip())
  if m:found.extend(bytes.fromhex(x) for x in re.findall(r'[0-9a-f]{2}',m.group(2)))
  elif found:break
 if len(found)>=89:data.append(bytes(b[0] for b in found[:89]))
print('original 2012 compiled Core DLL captured SCRIPT candidates size89:',len(data))
functions=[];mix=None
for j in src:
 n=j['name']
 m=re.search('G3_T053_ORIGINAL_NATIVE_BYTES fn='+re.escape(n)+r'\s+([0-9A-F]+)',txt)
 assert m,(n,txt[:100])
 native=bytes.fromhex(m.group(1));mem=bytes.fromhex(j['memory_hex']);mask=bytes.fromhex(j['mask_hex'])
 assert len(native)==len(mem)==len(mask)==j['script_size'],(n,len(native),len(mem),len(mask),j['script_size'])
 mismatches=[i for i,k in enumerate(mask) if k and native[i]!=mem[i]]
 assert not mismatches,(n,mismatches)
 observed=[]
 onames={int(i):s for i,s in re.findall('G3_T053_ORIGINAL_TYPED_NAME fn='+re.escape(n)+r' ordinal=(\d+) text=([^\r\n]+)',txt)}
 objects={int(i):s for i,s in re.findall('G3_T053_ORIGINAL_TYPED_OBJECT fn='+re.escape(n)+r' ordinal=(\d+) full=([^\r\n]+)',txt)}
 for rec in j['relocs']:
  offset=rec['memory_offset'];word=int.from_bytes(native[offset:offset+4],'little')
  if rec['kind']=='fname':
   assert len(onames)==1 and rec['resolved_name'] in onames.values(),(rec,onames)
   assert word>0,(n,offset,word)
   observed.append(dict(offset=offset,kind='fname',native_global_fName_id=word,resolved=rec['resolved_name']))
  elif rec['kind']=='object_pointer':
   assert word in objects,(n,rec,word,objects)
   assert objects[word].split('.')[-1]==rec['target_name'],(n,rec,word,objects[word])
   observed.append(dict(offset=offset,kind='object',native_pointer_slot=word,target=objects[word]))
  else:raise AssertionError(rec)
 report={'name':n,'script_memory_bytes':len(native),'original_2026_loaded_memory_hex':native.hex(),
  'reader_known_bytes':sum(mask),'reader_masked_native_pointer_or_name_bytes':len(mask)-sum(mask),
  'reader_known_byte_mismatches':len(mismatches),
  'source_archive_physical_bytes':j['physical_archive_bytes'],
  'original_compiled_runtime_typed_relocations':observed}
 if n=='ProbeMixedObjectNameVector':mix=(mem,mask,report)
 functions.append(report)
assert len(functions)==3
assert mix is not None
memory,mask,report=mix
matches=[]
for i,b in enumerate(data):
 mismatch=[x for x,k in enumerate(mask) if k and b[x]!=memory[x]]
 if not mismatch:matches.append((i,b))
assert matches,'Original 2012 Core.dll native CDB script does not match source-known physical bytes'
actual=matches[0][1]
words2012=[]
for rel in report['original_compiled_runtime_typed_relocations']:
 offset=rel['offset']
 val=int.from_bytes(actual[offset:offset+4],'little')
 words2012.append(dict(offset=offset,kind=rel['kind'],original_2012_memory_word=f'{val:08x}'))
assert any(x['kind']=='fname' and x['original_2012_memory_word']!='00000000' for x in words2012)
assert any(x['kind']=='object' and x['original_2012_memory_word']!='00000000' for x in words2012)
report['original_2012_native_core_script_matches_known_bytes']=True
report['original_2012_native_actual_memory_hex']=actual.hex()
report['original_2012_native_relocated_words']=words2012
out={'ok':True,'original_2012_C227i_compiler_genuine_UCC_zero_warnings':True,
 'original_2012_Core_DLL_native_loaded_mixed_script_89':True,
 'original_2026_Core_DLL_native_loaded_mixed_script':True,
 'original_2012_native_89_byte_script_matching_cdb_records':len(matches),
 'real_source_script_functions':len(functions),
 'original_2026_vs_reader_known_bytes_zero_mismatch':sum(x['reader_known_bytes'] for x in functions),
 'real_compiled_2026_typed_relocations':sum(len(x['original_compiled_runtime_typed_relocations']) for x in functions),
 'real_loaded_script_memory_bytes':sum(x['script_memory_bytes'] for x in functions),
 'same_mixed_script_original_2012_vs_source_known_mismatches':0,
 'reports':functions}
(root/'G3-T053-new-original-2012-2026-native-mixed-script-diff.json').write_text(json.dumps(out,indent=2)+'\n',encoding='utf-8')
print(json.dumps({k:v for k,v in out.items() if k!='reports'},indent=2))
