from pathlib import Path
import json,re,math,hashlib,collections
base=Path(r'C:\Temp\UnrealDB\O06')
rows=json.loads((base/'G5-UObject-on-disk-tagged-matched-native.json').read_text(encoding='utf8'))
lookup=json.loads((base/'G5-UObject-ref-and-name-lookup.json').read_text(encoding='utf8'))
text=(base/'G5-UObject-native-loaded-tagged-all.txt').read_text(encoding='utf-8-sig')
blocks=dict(re.findall(r'G5_NATIVE_OBJECT_BEGIN ([^\r\n]+)(.*?)G5_NATIVE_OBJECT_END [^\r\n]+',text,re.S))
native={}
for cls,body in blocks.items():
 found={}
 for val in re.findall(r'G5_NATIVE_TAG_VALUE\s*([^\r\n]+)',body):
  name,sep,v=val.partition('=')
  if sep:found[name.strip()]=v.strip()
 native[cls.rsplit('Entry.',1)[-1]]=found

def floats(string):
 return [float(x) for x in re.findall(r'(?<![A-Za-z_])(?:[-+]?(?:\d+\.\d+|\d+)(?:[Ee][-+]?\d+)?)',string)]
def dimparse(s,default):
 return {k:float(v) for k,v in re.findall(r'\b(X|Y|Z|Pitch|Yaw|Roll)=([-+]?[0-9.]+)',s)}|{}
def compare(t,stored):
 kind=t['kind']; value=t['value']
 if kind=='byte' and t['name']=='CsgOper':
  mapping={0:'CSG_Active',1:'CSG_Add',2:'CSG_Subtract',3:'CSG_Intersect',4:'CSG_Deintersect'}
  return mapping.get(int(value))==stored
 if kind in ('byte','int32'):
  return int(stored)==value
 if kind=='boolean_tag_bit':
  return stored.lower()==('true' if value else 'false')
 if kind=='float32':
  return abs(float(stored)-float(value))<=2e-5
 if kind=='fname_index':
  return stored==lookup['names'][value]
 if kind=='package_object_index':
  if int(value)==0:return stored.lower()=='none'
  dest=lookup['refs'].get(str(value))
  return bool(dest and dest['path'] and (stored==f"{dest['class']}'{dest['path']}'" or stored.endswith("'"+dest['path']+"'")))
 if kind=='fvector_float32_xyz':
  d=dimparse(stored,0)
  return all(math.isclose(float(value[k]),float(d.get(k.upper(),0)),rel_tol=1e-6,abs_tol=1e-5) for k in ('x','y','z'))
 if kind=='frotator_int32_pitch_yaw_roll':
  d=dimparse(stored,0)
  return all(int(value[k])==int(d.get(k.title(),0)) for k in ('pitch','yaw','roll'))
 if kind=='fscale_vector_rate_axis':
  d=dimparse(stored,1)
  axis={0:'SHEER_None',1:'SHEER_XY',2:'SHEER_XZ',3:'SHEER_YX',4:'SHEER_YZ',5:'SHEER_ZX',6:'SHEER_ZY'}
  ok=all(math.isclose(float(value['scale'][k]),float(d.get(k.upper(),1)),rel_tol=2e-6,abs_tol=1e-5) for k in ('x','y','z'))
  ok=ok and math.isclose(float(value['sheer_rate']),float(re.search(r'SheerRate=([-+0-9.]+)',stored).group(1) if 'SheerRate=' in stored else 0),abs_tol=1e-5)
  ok=ok and (axis.get(int(value['sheer_axis'])) in stored if value['sheer_axis'] else 'SheerAxis' not in stored)
  return ok
 if kind=='fstring_ansi_archive':
  return stored.strip('"')==value['ascii_text']
 if kind=='fpointregion_zone_leaf_number':
  ref=lookup['refs'].get(str(value['zone_object_reference']))
  if not ref or not ref['path']:return False
  match=re.search(r"Zone=(\w+)'([^']+)'",stored)
  if not match:return False
  leaf=re.search(r'iLeaf=([-+]?[0-9]+)',stored)
  znum=re.search(r'ZoneNumber=(\d+)',stored)
  actualLeaf=int(leaf.group(1)) if leaf else 0
  actualZone=int(znum.group(1)) if znum else 0
  return (match.group(1)==ref['class'] and match.group(2)==ref['path']
          and int(value['i_leaf'])==actualLeaf and int(value['zone_number'])==actualZone)
 return None
results=[];counter=collections.Counter();problems=[]
for obj in rows:
 if 'error' in obj:problems.append(('reader_error',obj));continue
 live=native.get(obj['name'],{})
 for tag in obj['tags']:
  key=tag['name']+('('+str(tag['array_index'])+')' if tag['array_index'] else '')
  # Unreal ExportProperties includes (0) when ArrayDim > 1.
  if key not in live and key==tag['name'] and key+'(0)' in live:key=key+'(0)'
  if key not in live:
   problems.append(('native_no_exported_property',obj['idx'],obj['name'],tag['kind'],key))
   counter['not_exported']+=1
   continue
  val=live[key]
  try:eq=compare(tag,val)
  except Exception as e:eq='exception:'+str(e)
  if eq is None:
   counter['opaque_unverified']+=1
   problems.append(('opaque_requires_exact_codec',obj['idx'],obj['name'],tag['kind'],key,val))
  elif eq is True:counter['matched_'+tag['kind']]+=1
  else:
   counter['mismatch']+=1
   problems.append(('mismatch',obj['idx'],obj['name'],tag['kind'],key,tag['value'],val,eq))
  results.append({'idx':obj['idx'],'class':obj['class'],'name':obj['name'],'property':key,'kind':tag['kind'],'on_disk':tag['value'],'native_exported':val,'match':eq})
summary={'genuine_original_sha256':hashlib.sha256((base/'Native-UCC'/'System'/'Entry.unr').read_bytes()).hexdigest(),
'compiled_native_log_sha256':hashlib.sha256((base/'G5-UObject-native-loaded-tagged-all.txt').read_bytes()).hexdigest(),
'original_33_loaded_classes':len(rows),'exactly_compared':sum(v for k,v in counter.items() if k.startswith('matched_')),
'kinds':dict(counter),'problems':problems,'total_tags':sum(len(r.get('tags',[])) for r in rows)}
(base/'G5-UObject-native-tagged-property-comparison.json').write_text(json.dumps({'summary':summary,'comparisons':results},indent=2),encoding='utf8')
print(json.dumps(summary,indent=2)[:15500])
