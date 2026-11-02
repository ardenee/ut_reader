import pefile,struct,collections,json
paths=[('v432',r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll'),
('469f',r'C:\Temp\UnrealDB\O06\Native-469f\System\Core.dll')]
a=pefile.PE(paths[0][1]);b=pefile.PE(paths[1][1])
va=a.OPTIONAL_HEADER.ImageBase;vb=b.OPTIONAL_HEADER.ImageBase
v432=list(struct.unpack('<57I',a.get_data(0x2889c,57*4)))
n469=list(b.get_data(0x4b4e0,57))
v469tab=list(struct.unpack('<32I',b.get_data(0x4b47c,32*4)))
assert all(va<=x<va+a.OPTIONAL_HEADER.SizeOfImage for x in v432)
assert max(n469)<len(v469tab)
v469=[v469tab[i] for i in n469]
assert all(vb<=x<vb+b.OPTIONAL_HEADER.SizeOfImage for x in v469)
groups=[{i for i in range(57) if vals[i]==val} for vals in (v432,v469) for val in set(vals)]
diff=[]
for i in range(57):
 g1={j for j in range(57) if v432[j]==v432[i]}
 g2={j for j in range(57) if v469[j]==v469[i]}
 if g1!=g2:diff.append(dict(token=f'{i:02X}',v432_group=sorted(g1),v469f_group=sorted(g2),v432_rva=hex(v432[i]-va),v469f_rva=hex(v469[i]-vb)))
res={'two_original_compiled_core_methods':{'v432':'0x28460 thunk from original RVA2860','469f':'0x4ac20'},'token_range':'0x00..0x38','v432_unique_handler_targets':len(set(v432)),'v469f_unique_handler_targets':len(set(v469)),'v432_469f_same_case_equivalence_partition':len(diff)==0,'changed_token_dispatch_partition':diff,'v432_dispatch':{f'{i:02X}':hex(v432[i]-va) for i in range(57)},'v469f_dispatch':{f'{i:02X}':hex(v469[i]-vb) for i in range(57)}}
print(json.dumps({k:v for k,v in res.items() if k not in ('v432_dispatch','v469f_dispatch')},indent=2))
open(r'C:\Temp\UnrealDB\O06\G3-T052-original-v432-v469f-compiled-57-opcode-dispatch.json','w').write(json.dumps(res,indent=2)+'\n')
