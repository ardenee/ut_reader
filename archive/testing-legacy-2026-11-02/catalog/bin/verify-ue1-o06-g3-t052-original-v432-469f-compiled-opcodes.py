#!/usr/bin/env python3
"""Frozen G3-T052: original stock v432 & real 469f compiled Core.dll UStruct::SerializeExpr full 57-case dispatch, genuine 469f loaded seven-case script runtime, source parser byte/typed ref parity. No replay accepted native engines."""
import hashlib,json,re,struct
from pathlib import Path
import pefile
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
PFX='20261031-G3-B07-'
sealed={
 'G3-T052-original-v432-v469f-compiled-57-opcode-dispatch.json':'4d30b51c2832c7106b810aa90850d110bf047acd7e9275cd444780807fc7198c',
 'G3-T052-original-2-compiled-CoreDLL-full-57-switch-compare.py':'136979cfbbe0d46345bb89d2137f0ec988d86dc4fa707b455b0eae61d3a6d914',
 'G3-T052-original-469f-real-Engine-u-dispatch-corpus.json':'72acaa803fbffb2f1cd1ce3510b7e1cc0ee3546b54db0f967a1ca503c7c1b09d',
 'G3-T052-scan-real-original-469f-Engine-u-7-dispatch-branches.php':'8dbb4a2122665117121097f1df0d213521c04a2bf916bfb22cc7562d617a23d9',
 'G3-T052-original-469f-shipped-Core-7handler-script-native.cpp':'632a5de2c2e03c5c1edbe488ffb9afb3f9ee79291ab01b9a81075c3ef8574c58',
 'G3-T052-original-469f-shipped-Core-script-native.log':'03f28710c68a3c658d29156ea2e36981558412efba280fa2c42f1f0fcd16b37e',
 'G3-T052-original-469f-shipped-Core-script-build.log':'00018091d9850498232a0e31253b4d4e50bdaee16eea7f8733c6e940f46d6a9c',
 'G3-T052-native-469f-compiled-script-vs-current-7handlers.py':'ba9ce3533f6895698acd48eb86e89ec5080cfa4199f8f5a0e7b448e5caeb08af',
 'G3-T052-new-original-469f-real-runtime-current-native-comparison.json':'c8ffb6dc749899262cc757ecf33ba1d0f0570c8c27a843ed7ed5a36d5306bf78',
 'G3-T052-current-original-469f-Engine7-native-compare.php':'68e015c1315a677787d44fa7313a7a81d327dfaae537dc7b6c5b058e7e76ad5b',
 'G3-T052-current-original-469f-selected-reader.json':'820c9cb1d764c40a079ddb8c77fd4b2225911b2fef1394deb1c95f21c945292c',
 'G3-T052-new-original-v432-469f-enum-delta-scan.py':'d7135bb8f1e9600c9f1bb118573a4bee17c18a44115a2bb41f020bfea88cce56'
}
checks=0;errors=[]
def check(v,why):
 global checks
 checks+=1
 if not v:errors.append(why)
for name,sha in sealed.items():
 p=EV/(PFX+name)
 check(p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==sha,'original 469f native compiled/oracle immutable SHA '+name)
source=ROOT/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php'
check(hashlib.sha256(source.read_bytes()).hexdigest()=='b309c89b21c88233a4de459b183b23dcdd2c4e4deb1fc2ba56a61f2ad34330bd','production source-linked real parser SHA')
binary_paths=[
 (Path(r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll'),'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'),
 (Path(r'C:\Temp\UnrealDB\O06\Native-469f\System\Core.dll'),'80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2'),
 (Path(r'C:\Temp\UnrealDB\O06\Native-469f\System\Engine.u'),'6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b'),
]
for path,sha in binary_paths:check(path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest()==sha,'genuine unmodified original engine binary/package '+str(path))
check(hashlib.sha256((EV/'20261028-G3-T051-original-v432-control-flow-native-compare.json').read_bytes()).hexdigest()=='457fd47987390226c24c57ec92c203510d7c30bf4c0c505d885464b7f9482bee','prior original compiled stock v432 positive script native/current acceptance retained, no native rerun')
a=pefile.PE(str(binary_paths[0][0]));b=pefile.PE(str(binary_paths[1][0]))
v432_base=a.OPTIONAL_HEADER.ImageBase;v469_base=b.OPTIONAL_HEADER.ImageBase
jmp=a.get_data(0x2860,5)
check(jmp[0]==0xe9 and 0x2865+int.from_bytes(jmp[1:],'little',signed=True)==0x28460,'original stock v432 exported SerializeExpr points to compiled nontrivial true machine method RVA28460')
v432=list(struct.unpack('<57I',a.get_data(0x2889c,57*4)))
v469_idx=list(b.get_data(0x4b4e0,57))
tbl=list(struct.unpack('<32I',b.get_data(0x4b47c,32*4)))
check(max(v469_idx)<len(tbl),'real original 469f compiled second-level jump table valid')
v469=[tbl[i] for i in v469_idx]
check(all(v432_base<=x<v432_base+a.OPTIONAL_HEADER.SizeOfImage for x in v432),'original v432 every machine dispatch target resides within actual shipped x86 image')
check(all(v469_base<=x<v469_base+b.OPTIONAL_HEADER.SizeOfImage for x in v469),'original 469f every machine dispatch target resides within actual shipped x86 image')
table=json.loads((EV/(PFX+'G3-T052-original-v432-v469f-compiled-57-opcode-dispatch.json')).read_text('utf-8'))
check(len(table['v432_dispatch'])==len(table['v469f_dispatch'])==57,'all 57 actual compiled switch table entries fully accounted')
for i in range(57):
 key=f'{i:02X}'
 check(table['v432_dispatch'][key]==hex(v432[i]-v432_base)
  and table['v469f_dispatch'][key]==hex(v469[i]-v469_base),
  'original compiled v432/469f opcode table exact true machine target for '+key)
check(len(set(v432))==24 and len(set(v469))==25,'real compiled jump case groups 24 v432 vs 25 469f, no unsupported assertion of byte-identical machine code')
diff=[i for i in range(57) if {j for j in range(57) if v432[i]==v432[j]}!={j for j in range(57) if v469[i]==v469[j]}]
check(diff==[4,13,14,29,30,34,35] and [int(x['token'],16) for x in table['changed_token_dispatch_partition']]==diff,
 'real compiled opcode dispatch differences exactly 7 case memberships 0x04/0x0D/0x0E,0x1D/0x1E,0x22/0x23')
check(b.get_data(0x1cf70,24)==b.get_data(0x47410,24)
 and b.get_data(0x1cf70,24).hex()=='558bec568b75088bce6a04ff750c8b16ff52048bc65e5dc3',
 'real original compiled 469f integer and float operator<< archive helper code identical; both read/write exactly4 bytes, no byte-format difference')
hdr1=Path(r'L:\Source\Games\UT99\Unreal Tournament v432\Core\Inc\UnStack.h').read_text('latin1')
hdr2=Path(r'L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Inc\UnStack.h').read_text('latin1')
def enums(txt):
 t=txt[txt.index('enum EExprToken'):];t=t[:t.index('};')]
 return re.findall(r'^\s*(EX_[A-Za-z0-9_]+)\s*=\s*([^,\r\n]+)',t,re.M)
ta,tb=enums(hdr1),enums(hdr2)
check(len(ta)==len(tb)==89 and ta==tb,'original v432 and original 469f source both define exactly same 89 explicit opcode assignments with no 469f-only token IDs/values')
native=(EV/(PFX+'G3-T052-original-469f-shipped-Core-script-native.log')).read_bytes()
native=native[2:].decode('utf-16le') if native.startswith(b'\xff\xfe') else native.decode('utf-8')
check('G3_T052_GENUINE_469F_CORE_ENGINE_PACKAGE=' in native and 'bad=0' in native
 and 'G3_T052_GENUINE_469F_NATIVE_SCRIPT_SAMPLES=48' in native,
 'separate newly compiled original 469f C++ x86 loads unchanged Engine.u and 48 real UFunction/UState scripts without native exception')
loader=(EV/(PFX+'G3-T052-original-469f-shipped-Core-7handler-script-native.cpp')).read_text('utf-8')
check('UObject::LoadPackage(' in loader and 'UState::StaticClass()' in loader
 and 'UFunction::StaticClass()' in loader and 'FObjectIterator' in loader and 'f->Script(i)' in loader,
 'real 469f original compiled C++ extracts genuine UStruct bytecode, recognizes actual UState 0x0D and verifies native UObject pointer identities')
for k in ['error','fatal error','G3_T052_GENUINE_469F_CORE_ENGINE_PACKAGE=00000000']:
 check(k not in native.lower() if k=='error' else k not in native,'no original 469f runtime failed load or crash '+k)
records={}
for m in re.finditer(r'G3_T052_GENUINE_469F_SCRIPT full=([^\r\n]+?) len=(\d+) native_hex=([0-9A-Fa-f]+)',native):
 records[m.group(1)]=bytes.fromhex(m.group(3))
 check(len(records[m.group(1)])==int(m.group(2)),'original compiled 469f true loaded memory script exact positive length '+m.group(1))
ptr={}
for m in re.finditer(r'G3_T052_GENUINE_469F_RUNTIME_POINTER fn=([^\r\n]+?) offset=(\d+) target=([^\r\n]+)',native):
 ptr.setdefault(m.group(1),[]).append((int(m.group(2)),m.group(3)))
stored=(EV/(PFX+'G3-T052-current-original-469f-selected-reader.json')).read_bytes()
stored=stored[2:].decode('utf-16le') if stored.startswith(b'\xff\xfe') else stored.decode('utf-8-sig')
rows=json.loads(stored)['rows']
check(len(records)==len(rows)==48,'all 48 original real compiled 469f functions + state native memory read by current genuine source-selected reader')
totals={'native_script_bytes':0,'known_bytes':0,'pointer_matches':0,'missing_pointer_targets':0,'literal_mismatches':0}
from collections import Counter
tokens=Counter()
for r in rows:
 name=r['fullname'];actual=records.get(name,b'');sourcebytes=bytes.fromhex(r['memory_hex']);mask=bytes.fromhex(r['mask_hex'])
 check(len(actual)==len(sourcebytes)==len(mask)==r['size'],'original compiled 469f exact Script.Num source memory length and full byte reconstruction '+name)
 for i,k in enumerate(mask):
  if k:
   totals['known_bytes']+=1
   if actual[i]!=sourcebytes[i]:totals['literal_mismatches']+=1
 totals['native_script_bytes']+=len(actual)
 for tok,count in r['real_expression_token_counts'].items():tokens[int(tok)]+=count
 for rel in r['relocations']:
  if rel['kind']!='object_pointer':continue
  match=any(offset==rel['memory_offset'] and full.split('.')[-1]==rel['target_name'] for offset,full in ptr.get(name,[]))
  if match:totals['pointer_matches']+=1
  else:totals['missing_pointer_targets']+=1
  check(match,'actual original engine UObject* pointer at source decoded archive relocation slot and exact target identity '+name+' offset'+str(rel['memory_offset']))
for k,expected in {'native_script_bytes':9107,'known_bytes':5075,'pointer_matches':904,'missing_pointer_targets':0,'literal_mismatches':0}.items():
 check(totals[k]==expected,'real 469f native x86 source/memory/parity identity true '+k)
for tok in diff:check(tokens[tok]>0,'original genuine shipped 469f Core.dll live UStruct script reaches original compiled dispatch difference opcode '+hex(tok))
corpus_path=EV/(PFX+'G3-T052-original-469f-real-Engine-u-dispatch-corpus.json')
cbytes=corpus_path.read_bytes()
corpus=json.loads(cbytes[2:].decode('utf-16le') if cbytes.startswith(b'\xff\xfe') else cbytes.decode('utf-8-sig'))
check(corpus['genuine_original_469f_Engine_u_processed_scripts']==1334
 and corpus['exceptions_first']==[],'all 1334 genuinely shipped 469f Engine.u decoded source scripts had no exceptions or fabricated expression cases')
for tok in diff:check(corpus['genuine_source_opcode_counts'].get(str(tok),0)>=tokens[tok],'original full 469f genuine corpus reached all 7 dispatch differences, native subset coverage')
v=json.loads((EV/(PFX+'G3-T052-new-original-469f-real-runtime-current-native-comparison.json')).read_text('utf-8'))
for k,expected in {'samples':48,'known_bytes':5075,'known_mismatches':0,'typed_pointer_matches':904}.items():
 check(v['summary'][k]==expected,'independent native vs production current source comparison original sealed JSON '+k)
check(len(v['rows'])==48 and all(not r['known_mismatches'] and not r['real_native_object_pointer_misses'] for r in v['rows']), 'independent native comparison 48/48 all zero literal and typed runtime object mismatches')
check(not errors,'all actual 469f original engine cases and source-specific current syntax valid')
result={'ok':not errors,'checks':checks,'errors':errors,
 'full_frozen_g3_ids':{'T052':'PASS' if not errors else 'BLOCKED'},
 'original_engines':{'stock_v432':binary_paths[0][1],'OldUnreal_469f':binary_paths[1][1]},
 'original_compiled_57_real_token_handlers':57,'original_89_token_assignments_identical':len(ta),
 'original_compiled_dispatch_group_differences':[f'{i:02X}' for i in diff],
 'new_original_469f_compiled_UFunction_and_UState_loaded_native':len(records),
 'original_469f_total_native_script_bytes':totals['native_script_bytes'],
 'original_native_loaded_literal_byte_matches':totals['known_bytes'],
 'original_469f_native_real_UObject_pointer_target_matches':totals['pointer_matches'],
 'new_original_x86_compiled_delta_opcode_native_coverage':{f'{i:02X}':tokens[i] for i in diff},
 'original_469f_genuine_corpus_decoded_scripts':1334,
 'previous_accepted_original_native_executions_rerun':0,
 'G3_overall_complete':False}
print(json.dumps(result,indent=2))
raise SystemExit(0 if not errors else 1)
