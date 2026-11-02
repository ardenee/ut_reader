from pathlib import Path
import re,collections
paths=[
 r'L:\Source\Games\UT99\Unreal Tournament v432\Core\Inc\UnStack.h',
 r'L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Inc\UnStack.h'
]
allrows=[]
for f in paths:
 s=Path(f).read_text(encoding='latin1')
 a=s.find('enum EExprToken')
 print(Path(f).parent.parent.parent.name,'token enum location',a)
 if a<0:continue
 s=s[a:s.index('};',a)]
 toks=re.findall(r'^\s*(EX_[A-Za-z0-9_]+)\s*=\s*([^,\r\n]+)',s,re.M)
 print('token assignments',len(toks));print('sample',toks[:8]);allrows.append(dict(toks))
if len(allrows)==2:
 old,new=allrows
 print('v432-only',[(k,v) for k,v in old.items() if k not in new])
 print('469f-only',[(k,v) for k,v in new.items() if k not in old])
 print('changed',[(k,v,new[k]) for k,v in old.items() if k in new and v!=new[k]])
