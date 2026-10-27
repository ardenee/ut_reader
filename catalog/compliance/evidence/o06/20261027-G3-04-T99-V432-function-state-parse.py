from pathlib import Path
import re,json,collections
p=Path(r'C:\Temp\UnrealDB\O06\G3-04-T99-V432-function-state-original.log')
text=p.read_text(encoding='utf-8',errors='replace')
matches=list(re.finditer(r'(?m)^(G304_T99_V432_UFUNCTION_POSTLOAD|G304_T99_V432_USTATE_POSTLOAD)\s*$',text))
out=[];errors=[]
for i,m in enumerate(matches):
 seg=text[m.end():matches[i+1].start() if i+1<len(matches) else len(text)]
 rows=[]
 for z in re.finditer(r'(?m)^([0-9a-fA-F]{8})[ \t]+((?:[0-9a-fA-F]{8}[ \t]*){1,8})\r?$',seg):
  rows.append((int(z[1],16),[int(v,16) for v in z[2].split()]))
 if len(rows)<2:
  errors.append((i,'insufficient rows',seg[:100]));continue
 this=rows[0][0]-0x10;linker,idx=rows[0][1][:2]
 if rows[1][0]!=this+0x70:
  errors.append((i,'bad offset',rows[:2]));continue
 data=rows[1][1][:]
 if len(data)<6 and len(rows)>2 and rows[2][0]==this+0x70+4*len(data):
  data.extend(rows[2][1])
 kind='function' if 'UFUNCTION' in m[1] else 'state'
 if kind=='function':
  if len(data)<4:errors.append((i,'function fields length',data));continue
  f,nd,pr,retval=data[:4]
  fields={'function_flags':f,'native_index':nd&0xffff,'rep_offset':(nd>>16)&0xffff,
      'operator_precedence':pr&0xff,'num_parms':(pr>>8)&0xff,
      'parms_size':(pr>>16)&0xffff,'return_value_offset':retval&0xffff}
 else:
  if len(data)<6:errors.append((i,'state fields length',data));continue
  fields={'probe_mask_hex':(''.join('%02x'%b for x in data[:2] for b in x.to_bytes(4,'little'))),
      'ignore_mask_hex':(''.join('%02x'%b for x in data[2:4] for b in x.to_bytes(4,'little'))),
      'state_flags':data[4], 'label_table_offset':data[5]&0xffff}
 out.append({'event':i,'kind':kind,'linker':linker,'idx':idx,'object_this':this,'native':fields})
print(json.dumps({'events':len(matches),'parsed':len(out),'error_count':len(errors),'errors':errors[:3],
 'by_source':{str(a):len(b) for a,b in ((k,[v for v in out if (v['linker'],v['kind'])==k]) for k in sorted(set((x['linker'],x['kind']) for x in out)))},
 'native_values':out[:2]},indent=2))
p.with_name('G3-04-T99-V432-function-state-parsed.json').write_text(json.dumps({'summary':{'events':len(matches),'parsed':len(out),'errors':errors},'records':out},indent=2)+'\n')
