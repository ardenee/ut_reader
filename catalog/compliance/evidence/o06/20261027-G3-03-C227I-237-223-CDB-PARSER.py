from pathlib import Path
import re,json
root=Path(r'C:\Temp\UnrealDB\O06')
def read(name):
 return (root/name).read_text('utf-16',errors='replace').replace('\r','')
src=read('g3-03-C227i-actual-native-UStruct-postload.log')
segments=src.split('G303_C227I_ORIGINAL_NATIVE_UStruct_LOADED')[2:]
header={}
for f in segments:
 matches=[]
 for line in f.splitlines():
  m=re.match(r'^([0-9a-f]{8})\s+((?:[0-9a-f]{8}\s*)+)$',line.strip())
  if m:matches.append([m.group(1)]+m.group(2).split())
 if len(matches)<4:raise Exception(('missing dd',f[:90]))
 a,b,c,e=matches[:4]
 index=int(a[2],16)
 if index in header:raise Exception(('duplicate original native export',index))
 child=int(b[2],16)
 target=None
 if child:
  for x in matches[4:]:
   if int(x[0],16)==child+16:
    target={'linker':int(x[1],16),'zero_based_export_index':int(x[2],16)}
    break
  if not target:raise Exception(('no deref child',index))
 header[index]={'original_native_index_zero_based':index,'original_native_linker':int(a[1],16),'native_friendly_global_name_id':int(c[1],16),'native_scripttext_ptr':int(b[1],16),'native_children_ptr':child,'native_children_linked_target':target,'native_script_buf_ptr':int(c[2],16),'native_script_size':int(c[3],16),'native_script_alloc':int(c[4],16),'native_textpos':int(e[1],16),'native_line':int(e[2],16)}
assert len(header)==237,len(header)
del index  # reset previous header loop identity before next native-run captures
src=read('g3-03-C227i-script-actual-native-bytes.log')
parts=src.split('G303_C227I_ORIGINAL_SCRIPT_MEMORY')[2:]
scripts={}
for f in parts:
 lines=f.splitlines()
 mem=[]
 for line in lines:
  m=re.match(r'^([0-9a-f]{8})\s+((?:[0-9a-f]{2}[ \-]+){1,16})',line.strip())
  if m:
   hexbytes=re.findall(r'[0-9a-f]{2}',m.group(2))
   mem.extend(int(x,16) for x in hexbytes)
  m=re.match(r'^([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})$',line.strip())
  if m:
   idx=int(m.group(3),16)
   if int(m.group(1),16)%16==0 or True:
    # discriminate +0x10 line: second word is actual linker
    if idx in header and 'index' not in locals():
     index=idx
 if 'index' not in locals():raise Exception(('missing script identity',f[:100]))
 if index in scripts:raise Exception(('duplicate script native data',index))
 scripts[index]=bytes(mem[:36]).hex()
 del index
print('SCRIPT IDS FIRST:',list(scripts)[:18], 'LAST',list(scripts)[-5:]); assert len(scripts)==223,(len(scripts),list(scripts)[:15])
for idx,rec in header.items():
 if rec['native_script_size']>0:
  assert idx in scripts,(idx,rec['native_script_size'])
  assert len(bytes.fromhex(scripts[idx]))>=rec['native_script_size'],(idx,rec['native_script_size'],scripts[idx])
 elif idx in scripts:raise Exception(('extra',idx))
known_links={r['original_native_linker'] for r in header.values()}
assert len(known_links)==1,known_links
stats={'original_native_unique_UStruct_loaded':len(header),'original_native_script_buffers':len(scripts),'original_native_zero_scripts':len(header)-len(scripts),'original_native_max_scriptSize':max(x['native_script_size'] for x in header.values()),'actual_original_children_ptr_nonzero':sum(bool(x['native_children_ptr']) for x in header.values()),'original_native_script_sizes_count_greater_zero':sum(x['native_script_size']>0 for x in header.values())}
out=root/'g3-03-C227i-real-original-UStruct-237-script223-records.json'
out.write_text(json.dumps({'schema':1,'native_original_2012_C227i':stats,'original_compiled_source_linker':list(known_links)[0],'native_structs':header,'native_script_first36_hex':scripts},indent=2)+'\n',encoding='utf8')
print('OK COMPILED genuine C227i Core.dll',stats,'parse bytes',out.stat().st_size,'SCRIPT EXAMPLES',list(scripts.items())[:2])
