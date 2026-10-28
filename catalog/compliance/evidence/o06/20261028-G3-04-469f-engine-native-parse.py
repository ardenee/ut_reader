from pathlib import Path
import re,json,collections
p=Path(r'C:\Temp\UnrealDB\O06\g3-04-469f-engine-native-load.log')
s=p.read_text(encoding='utf-8',errors='replace')
matches=list(re.finditer(r'(?m)^G304_469F_ENGINE_ORIGINAL_NATIVE_(FUNCTION|STATE)_POSTLOAD\s*$',s))
rows=[];errors=[]
for i,m in enumerate(matches):
 seg=s[m.end():(matches[i+1].start() if i+1<len(matches) else len(s))]
 words=[]
 for z in re.finditer(r'(?m)^([0-9A-Fa-f]{8})[ \t]+((?:[0-9A-Fa-f]{8}[ \t]*){1,8})\r?$',seg):
  words.append((int(z[1],16),[int(v,16) for v in z[2].split()]))
 if len(words)<2 or len(words[0][1])<2:
  errors.append([i,'bad rows',len(words)]);continue
 this=words[0][0]-0x10
 linker,idx=words[0][1][:2]
 if words[1][0]!=this+0x70:
  errors.append([i,'bad field address',words[1][0],this+0x70]);continue
 data=words[1][1][:]
 if len(data)<6 and len(words)>2 and words[2][0]==this+0x70+4*len(data):data+=words[2][1]
 kind=m[1].lower()
 if kind=='function':
  if len(data)<4:errors.append([i,'bad function data']);continue
  flags,ni,parm,retval=data[:4]
  fields={'function_flags':flags,'native_index':ni&65535,'rep_offset':(ni>>16)&65535,
     'operator_precedence':parm&255,'num_parms':(parm>>8)&255,
     'parms_size':(parm>>16)&65535,'return_value_offset':retval&65535}
 else:
  if len(data)<6:errors.append([i,'bad state data']);continue
  fields={'probe_mask_hex':''.join('%02x'%b for n in data[:2] for b in n.to_bytes(4,'little')),
   'ignore_mask_hex':''.join('%02x'%b for n in data[2:4] for b in n.to_bytes(4,'little')),
   'state_flags':data[4],'label_table_offset':data[5]&65535}
 rows.append({'event':i,'linker':linker,'idx':idx,'kind':kind,'native':fields})
c=collections.Counter((r['linker'],r['kind']) for r in rows)
summary={'events':len(matches),'parsed':len(rows),'errors':errors[:10],
 'by_linker':{str(k):v for k,v in sorted(c.items())},
 'distinct_linker_export_kind':len(set((r['linker'],r['idx'],r['kind']) for r in rows)),
 'sample':rows[:3]}
Path(r'C:\Temp\UnrealDB\O06\g3-04-469f-engine-native-parsed.json').write_text(json.dumps({'summary':summary,'records':rows},indent=2)+'\n')
print(json.dumps(summary,indent=2))
