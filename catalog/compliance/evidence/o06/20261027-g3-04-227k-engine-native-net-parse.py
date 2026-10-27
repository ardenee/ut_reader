from pathlib import Path
import re,json,collections
p=Path(r'C:\Temp\UnrealDB\O06\g3-04-227k-engine-native-net-original.log')
s=p.read_text(encoding='utf-8',errors='replace')
hits=list(re.finditer(r'(?m)^G304_C227K_UNREAL_ENGINE_FN\s*$',s))
events=[]; errors=[]
for i,m in enumerate(hits):
    seg=s[m.end():hits[i+1].start() if i+1<len(hits) else len(s)]
    rows=[(int(z[1],16),[int(t,16) for t in z[2].split()]) for z in re.finditer(
        r'(?m)^([0-9a-fA-F]{8})[ \t]+((?:[0-9a-fA-F]{8}[ \t]*){1,8})\r?$',seg)]
    if len(rows)<4: errors.append((i,'too few rows',len(rows)));continue
    addr=rows[0][0]-0x10
    l=rows[0][1]
    field=next((x[:] for a,x in rows if a==addr+0xb8),[])
    follow=next((x for a,x in rows if a==addr+0xb8+4*len(field)),[])
    field.extend(follow)
    if len(l)<4 or len(field)<6:errors.append((i,'missing fields',l,field));continue
    events.append({'event':i,'native_obj':addr,'linker':l[3],
      'idx':l[1], 'flags':field[0],
      'native_index':field[1]&0xffff, 'rep_offset':(field[1]>>16)&0xffff,
      'operator_precedence':field[2]&0xff,'num_parms':(field[2]>>8)&0xff,
      'parms_size':field[5]&0xffff,'return_value_offset':(field[5]>>16)&0xffff})
out={'summary':{'events':len(hits),'parsed':len(events),'errors':errors,
 'linkers':dict(collections.Counter(hex(x['linker']) for x in events)),
 'native_net_count':sum(bool(x['flags']&0x40) for x in events)},
 'records':events}
dst=p.with_name('g3-04-227k-engine-native-net-original-parsed.json')
dst.write_text(json.dumps(out,indent=2)+'\n',encoding='utf-8')
print(json.dumps({'summary':out['summary'],'net_sample':[x for x in events if x['flags']&0x40][:12],
 'first_events':events[:3]},indent=2))
