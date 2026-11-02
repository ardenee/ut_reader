from pathlib import Path
import re,json,collections
base=Path(r'C:\Temp\UnrealDB\O06')
for variant in ('C227i','C227k'):
    path=base/f'G3-04-{variant}-original-function-state.log'
    s=path.read_text(encoding='utf-8',errors='replace')
    hits=list(re.finditer(r'(?m)^G304_C227[IK]_(FUNCTION|STATE)_POSTLOAD\s*$',s))
    out=[];errs=[]
    for i,m in enumerate(hits):
        seg=s[m.end():hits[i+1].start() if i+1<len(hits) else len(s)]
        rows=[(int(z[1],16),[int(t,16) for t in z[2].split()]) for z in
            re.finditer(r'(?m)^([0-9a-fA-F]{8})[ \t]+((?:[0-9a-fA-F]{8}[ \t]*){1,8})\r?$',seg)]
        if len(rows)<3:errs.append([i,'rows',len(rows)]);continue
        idx=rows[0][1][1]
        if variant=='C227i':
            baseobj=rows[0][0]-0x10
            field=next((v for addr, v in rows if addr==baseobj+0x70),[])
            if len(field)<4:errs.append([i,'fields',field]);continue
            flags,second,third,fourth=field[:4]
            fn={'function_flags':flags,'native_index':second&0xffff,
                'rep_offset':second>>16,'operator_precedence':third&0xff,
                'num_parms':(third>>8)&0xff,'parms_size':(third>>16)&0xffff,
                'return_value_offset':fourth&0xffff}
            state={'probe_mask_hex':''.join(f'{b:02x}' for x in field[:2] for b in x.to_bytes(4,'little')),
            'ignore_mask_hex':''.join(f'{b:02x}' for x in field[2:4] for b in x.to_bytes(4,'little'))}
        else:
            baseobj=rows[0][0]-0x10
            field=next((v[:] for addr,v in rows if addr==baseobj+0xb8),[])
            follow=next((v for addr,v in rows if addr==baseobj+0xb8+4*len(field)),[])
            field.extend(follow)
            if len(field)<6:errs.append([i,'fields',field]);continue
            fn={'function_flags':field[0],
              'native_index':field[1]&0xffff,'rep_offset':(field[1]>>16)&0xffff,
              'operator_precedence':field[2]&0xff,'num_parms':(field[2]>>8)&0xff,
              'parms_size':field[5]&0xffff,'return_value_offset':(field[5]>>16)&0xffff}
            state={'probe_mask_hex':''.join(f'{b:02x}' for x in field[:2] for b in x.to_bytes(4,'little')),
            'ignore_mask_hex':''.join(f'{b:02x}' for x in field[2:4] for b in x.to_bytes(4,'little')),
            'state_flags':field[4], 'label_table_offset':field[5]&0xffff}
        kind='function' if m[1]=='FUNCTION' else 'state'
        out.append({'event':i,'kind':kind,'idx':idx,'native':fn if kind=='function' else state,
          'object_this':baseobj,'raw_field_words':field})
    data={'summary':{'variant':variant,'events':len(hits),'parsed':len(out),'errors':errs,
        'kinds':dict(collections.Counter(x['kind'] for x in out))},'records':out}
    dest=base/f'G3-04-{variant}-original-function-state-parsed.json'
    dest.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8')
    print(json.dumps({'variant':variant,'summary':data['summary'],'first':out[:2]},indent=2))
