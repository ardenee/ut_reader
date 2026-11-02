from pathlib import Path
import re,json,collections
p=Path(r'C:\Temp\UnrealDB\O06\G3-04-C98-original-native-function-state.log')
text=p.read_text(encoding='utf-8', errors='replace')
pattern=re.compile(r'(?m)^(G304_C98_(?:UFunction_(?:NONNET|NET)|UState_LOADED))\s*$')
spans=list(pattern.finditer(text))
record=[]
errors=[]
for n,m in enumerate(spans):
    body=text[m.end():spans[n+1].start() if n+1<len(spans) else len(text)]
    lines=[]
    for match in re.finditer(r'(?m)^([0-9a-fA-F]{8})\s+((?:[0-9a-fA-F]{8}\s*){2,7})$',body):
        addr=int(match[1],16)
        words=[int(x,16) for x in match[2].split()]
        lines.append([addr,words])
    if len(lines)<2:
        errors.append({'event':n,'kind':m[1],'reason':'missing memory dwords','snippet':body[:150]})
        continue
    base=lines[0][0]-0x10
    if lines[1][0]!=base+0x60 or lines[0][1][1]>=2**32:
        errors.append({'event':n,'kind':m[1],'reason':'unexpected field addresses','a':lines[:2]})
        continue
    linker,exportid=lines[0][1][:2]
    words=lines[1][1][:]
    if len(words)<7 and len(lines)>2 and lines[2][0]==base+0x60+4*len(words):
        words.extend(lines[2][1])
    row={'event':n,'kind':m[1],'this':base,'linker':linker,'id':exportid,'raw':words}
    if m[1].startswith('G304_C98_UFunction'):
        if len(words)<4:
            errors.append({'event':n,'reason':'truncated UFunction memory','raw':words})
            continue
        row.update({'functionFlags':words[0], 'parmsSize':words[1]&0xffff,
                    'nativeIndex':words[1]>>16, 'repOffset':words[2]&0xffff,
                    'returnOffset':words[2]>>16, 'numParms':words[3]&0xff,
                    'precedence':(words[3]>>8)&0xff})
    else:
        if len(words)<7:
            errors.append({'event':n,'reason':'truncated UState memory','raw':words})
            continue
        row.update({'probeMask':(words[0]|words[1]<<32), 'ignoreMask':(words[2]|words[3]<<32),
                    'vfHashPtr':words[4],'stateFlags':words[5],
                    'labelOffset':words[6]&0xffff})
    record.append(row)
hist=collections.Counter(str(x['linker'])+':'+x['kind'] for x in record)
unique={key:len({x['id'] for x in record if str(x['linker'])+':'+x['kind']==key}) for key in hist}
out={'summary':{'captures':len(spans),'parsed':len(record),'bad':errors[:8],
                 'byLinkerAndKind':dict(hist),'unique':unique},
     'records':record}
q=p.with_name('G3-04-C98-native-function-state-parsed.json')
q.write_text(json.dumps(out,indent=2)+'\n',encoding='utf-8')
print(json.dumps(out['summary'],indent=2))
