from pathlib import Path
import re,json
p=Path(r'C:\Temp\UnrealDB\O06\g3-03-C98-MAY1998-100-original-native-scripts.log')
s=p.read_text('utf-16',errors='replace').replace('\r','')
parts=s.split('G303_C98_MAY1998_UStruct_NATIVE_LOADED')[2:]
records={}
for f in parts:
 lines=f.splitlines()
 dd=[];binary=[]
 for line in lines:
  m=re.match(r'^([0-9a-f]{8})\s+((?:[0-9a-f]{8}\s*)+)$',line.strip())
  if m:dd.append([int(x,16) for x in m.group(2).split()])
  b=re.match(r'^([0-9a-f]{8})\s+((?:[0-9a-f]{2}[ \-]+){1,16})',line.strip())
  if b:binary.extend(int(v,16) for v in re.findall(r'[0-9a-f]{2}',b.group(2)))
 if len(dd)<3:raise Exception(('bad original compiled CDB dd',f[:100]))
 header=dd[0];field=dd[1];tail=dd[2]
 if (len(header),len(field),len(tail))!=(2,4,4):raise Exception(('bad May1998 native field shape',header,field,tail))
 idx=header[1]
 if idx in records:raise Exception(('duplicate original May1998 native UStruct',idx))
 size=tail[3]
 if size>0 and len(binary)<size:raise Exception(('native bytes missing',idx,size,len(binary)))
 records[idx]={'native_original_Core_u_export_zero_based':idx,
  'original_native_linker':header[0],
  'original_native_scripttext_ptr':field[0],
  'original_native_children_ptr':field[1],
  'original_native_friendly_global_FName_index':field[3],
  'original_native_textpos':tail[0],
  'original_native_line':tail[1],
  'original_native_script_memory_ptr':tail[2],
  'original_native_script_size':size,
  'original_compiled_real_script_memory_bytes_hex':bytes(binary[:size]).hex()}
assert len(records)==100,len(records)
assert len({v['original_native_linker'] for v in records.values()})==1
nonzero=sum(x['original_native_script_size']>0 for x in records.values())
assert nonzero==96
assert sum(bool(x['original_native_children_ptr']) for x in records.values())==96
out=Path(r'C:\Temp\UnrealDB\O06\g3-03-C98-MAY1998-100-real-original-UStruct-96-scripts.json')
out.write_text(json.dumps({'schema':1,'original_May1998_Core_u_native_postload_struct_count':100,
 'original_May1998_native_nonzero_script_count':96,
 'original_May1998_native_max_script_length':max(x['original_native_script_size'] for x in records.values()),
 'original_May1998_native_children_ptr_nonzero':96,
 'original_compiled_source_linker':next(iter({v['original_native_linker'] for v in records.values()})),
 'native_records':records},indent=2)+'\n',encoding='utf8')
print('1998 original stock native CDB loaded',len(records),'script96','single linker',out.stat().st_size,'bytes; sample',records[456])
