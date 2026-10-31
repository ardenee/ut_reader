#!/usr/bin/env python3
"""G3 fixed T013: seven ORIGINAL compiled v432 Core.u UClass records and
previously sealed 88 original v432 Engine.u UClass records. Read-only:
no compiled game execution, no rerun of previously accepted native probes.
"""
import hashlib,json,pathlib,re,struct,sys
R=pathlib.Path(__file__).resolve().parents[2]
E=R/'catalog/compliance/evidence/o06'
P={
'catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php':'df4a525783fa7667ac14f405194e6ea8f2db8a5a22615b228ebbb6f59c33b35a',
'catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json':'772b85769a1773ab80bdfe2be278846e3d1c2977a5fd28eb2fef8e9df0049f7e',
'catalog/compliance/UE1-O06-G5-T99-UCLASS-WHOLE-NATIVE-SCRIPT.json':'c3447d0dc1092df8b4eaa92453846feeeeef7db2794d32264fddd722c81573fc',
'catalog/compliance/evidence/o06/20261028-G3-T013-v432-Core-seven-source-archive.json':'6593137474989fd6fa03cd6fb7882a3327cdb8080e10553e1b48717a0c833c7a',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-v432-original-Core-all-class-flags-within.log':'631379efb7a9c11bb529f67147491a1b939e68dc31e8217cdb11bd28d5eda3f1',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-v432-inherited-original-core-fields.log':'3c63226cca8a7f49ecb0fccd3854307046a9bd29f33a68b60d0322e23c92932c',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-v432-remaining-original-core-fields.log':'17619b0952b1db3dcafe69e9c069b539bfa5c3bf756113ff9bf36df141ed2ee8',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-v432-dependency-original-core-fields.log':'3646f312781985b399956532c21dd296c231535c78c2cc61517628326681f6ca',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-v432-archive-cursor-original-core-fields.log':'f18aa62cd790fa2a534c15488da9f67932b55d7fdaa99742cc2ec9504fc8827e',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-469f-original-Core-all-class-flags-within.log':'d303fc66408d40ce33a42dfaf4371aea801c5195d415acf2dba61bfeee515bfa',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-227i-original-Core-all-class-flags-within.log':'f1af9ff06bd5ab7a4fd56b7fc5789f890c0534c9a0e0bcd4a769cce0a75bfc95',
'catalog/compliance/evidence/o06/20261028-G3-UCLASS-227k-original-Core-all-class-flags-within.log':'c456fc1bb13b6d7a3691271e4a250f33273d923b5fd7a8691a4de6acc2d5984b',
}
B={
r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.u':'ae42de48103317761ffce4da2ee50300a57a653952d88d3451364f3d48975f70',
r'C:\Temp\UnrealDB\O06\Native-UCC\System\Core.dll':'237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8',
r'C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.u':'d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695',
r'C:\Temp\UnrealDB\O06\G5-UClass-v432-after-90-all-memory.dmp':'fb77216dabcb1e2152b27c82447184ecafe5869f888986c7452314fa5a4359e8',
}
checks=0;errors=[]
def ok(truth,msg):
 global checks
 checks+=1
 if not truth:errors.append(msg)
def sha(path):return hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()
for path,h in P.items():ok(sha(R/path)==h,'SHA current source/original compiled log '+path)
for path,h in B.items():ok(sha(path)==h,'SHA stock original engine/package/minidump '+path)
source=json.loads((E/'20261028-G3-T013-v432-Core-seven-source-archive.json').read_text())
rows={x['id']:x for x in source['classes']}
ok(len(rows)==7 and sorted(rows)==[2,7,328,329,630,653,662],'all seven original Core.u serialized UClass records')
ok(all(x['native_required_file_archive_complete'] for x in rows.values()),'all seven original Core.u class FArchive consumes exact source bytes')
g5=json.loads((R/'catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json').read_text())
ok(g5['decision']=='FULL' and g5['all_real_original_UClass_native_loaded']==88
 and g5['all_real_original_UClass_archives_fully_consumed']==88,'reused real shipped Engine.u 88 FULL')
ok(all(v=='VERIFIED' for v in g5['seven_gates'].values()),'v432 original Engine.u seven method gates source verified')
g5method=json.loads((R/'catalog/compliance/UE1-O06-G5-T99-UCLASS-WHOLE-NATIVE-SCRIPT.json').read_text())
ok(g5method['original_compiled']['Engine.u']['sha256']==B[r'C:\Temp\UnrealDB\O06\Native-UCC\System\Engine.u'],'88 Engine source-native original binary same compiler/load lineage')
# Actual SHIPPED v432 minidump global FName table; never turn package-local
# indices into transient process FName indices.
mem=pathlib.Path(r'C:\Temp\UnrealDB\O06\G5-UClass-v432-after-90-all-memory.dmp').read_bytes()
def u32(o):return struct.unpack_from('<I',mem,o)[0]
def u64(o):return struct.unpack_from('<Q',mem,o)[0]
ranges=[]
for i in range(u32(8)):
 d=u32(12)+12*i
 if u32(d)!=9:continue
 p=u32(d+8);base=u64(p+8)
 for k in range(u64(p)):
  pos=p+16+k*16;va=u64(pos);n=u64(pos+8)
  ranges.append((va,va+n,base));base+=n
def readva(addr,n):
 for a,b,o in ranges:
  if a<=addr and addr+n<=b:return mem[o+addr-a:o+addr-a+n]
 raise AssertionError('Original native VM unmapped '+hex(addr))
def word(addr):return struct.unpack('<I',readva(addr,4))[0]
names=word(0x10295d30);namecount=word(0x10295d34)
ok(4000<namecount<100000,'genuine original compiled v432 global FName table populated')
def native_name(idx):
 if idx==0:return 'None'
 assert 0<idx<namecount
 ptr=word(names+idx*4);assert ptr!=0
 v=bytearray()
 for n in range(256):
  b=readva(ptr+12+2*n,2)
  if b==b'\x00\x00':break
  v.extend(b)
 return v.decode('utf-16le')
ok(native_name(744)=='System' and native_name(20)=='Core','original native FName744=System and FName20=Core')
def body(file):return (E/file).read_bytes().decode('latin1','replace').replace('\r\n','\n').replace('\r','\n')
def events(text,marker):return text.split(marker+'\n')[1:]
line_re=re.compile(r'^\s*([0-9a-f]{8})\s{2,}((?:[0-9a-f]{8}\s*){1,4})\s*$',re.I)
def hexlines(block):
 out=[]
 for line in block.splitlines():
  m=line_re.match(line)
  if m:out.append((int(m[1],16),[int(w,16) for w in m[2].split()]))
 return out
def section(block,marker):
 m=re.search(r'(?ms)^'+re.escape(marker)+r'\n(.*?)(?=^ORIGINAL_|^NATIVE_CORE_CLASS_RECORD_FINISHED|\Z)',block)
 if not m:raise AssertionError('missing true original compiled method field '+marker)
 return [v for _,arr in hexlines(m[1]) for v in arr]
def pinclass(s):
 assert len(s)==4
 return tuple(s)
flags_text=body('20261028-G3-UCLASS-v432-original-Core-all-class-flags-within.log')
flags=events(flags_text,'G3_NATIVE_CLASS_V432')
ok(len(flags)==7,'seven real compiled v432 Core.UClass load field events')
loaded={}
for c in flags:
 nums=[v for _,arr in hexlines(c) for v in arr][:4]
 if len(nums)!=4:continue
 id,flag,within,config=nums
 ok(id not in loaded,'each native Core UClass export single original archive load')
 loaded[id]=(flag,within,config)
ok(sorted(loaded)==sorted(rows),'native compiled Core.u UClass class IDs match genuine stock archive')
ref_text=body('20261028-G3-UCLASS-v432-inherited-original-core-fields.log')
refevents=events(ref_text,'G3_CLASS_NATIVE_7_INHERITED')
ok(len(refevents)==7,'seven original native inherited-reference groups')
objects={};reference_results={}
for c in refevents:
 start=c.split('G3_REF_SUPER\n',1)[0]
 m=hexlines(start)
 if not m:continue
 addr,vals=m[0]
 id=vals[0]
 objects[id]=addr-0x14
 checks_here={}
 for label in ('SUPER','NEXT','SCRIPT_TEXT','CHILDREN'):
  m=re.search(r'(?ms)^G3_REF_'+label+r'\n(.*?)(?=^G3_REF_|^G3_CLASS_INHERITED_END|\Z)',c)
  if not m:continue
  sub=m[1]
  if 'NATIVE_NULL_REF' in sub:checks_here[label]=None
  elif 'NATIVE_REFERENT_OBJECT_INDEX' in sub:
   tail=sub.split('NATIVE_REFERENT_OBJECT_INDEX\n',1)[1]
   e=hexlines(tail)
   checks_here[label]=e[0][1][0] if e else 'UNKNOWN'
 reference_results[id]=checks_here
ok(sorted(objects)==sorted(rows),'all seven original live UClass instances have native self pointer')
ok(sorted(reference_results)==sorted(rows),'all seven source-class inherited pointer sections captured')
for id,s in rows.items():
 native=loaded.get(id)
 if not native:continue
 flag,within,conf=native
 ok(flag==s['ClassFlags'],'original actual UClass ClassFlags '+s['name'])
 ok(within==objects.get(s['ClassWithin']-1),'original actual ClassWithin pointer identity '+s['name'])
 ok(native_name(conf)==s['ClassConfigName'],'original global FName config '+s['name'])
 refs=reference_results.get(id,{})
 for label,key in [('SUPER','super'),('NEXT','next'),('SCRIPT_TEXT','scriptText'),('CHILDREN','children')]:
  value=refs.get(label,'MISSING')
  ref=s[key]
  ok((ref==0 and value is None) or (ref>0 and value==ref-1),
      'original native UField/UStruct inherited '+label+' '+s['name'])
details=body('20261028-G3-UCLASS-v432-remaining-original-core-fields.log')
parts=events(details,'G3_NATIVE_CORE_COMPLETE_FIELDS')
ok(len(parts)==7,'seven original UClass GUID/array/scalar events')
# FDependency class pointers are process-relative. Verify their actual loaded
# UObject.Index in the SAME compiled process as the dependency record.
dep_runtime={}
dep_records=events(body('20261028-G3-UCLASS-v432-dependency-original-core-fields.log'),
                   'ORIGINAL_UCLASS_DEPENDENCY_ACTUAL_OBJECT_INDEX')
ok(len(dep_records)==7,'all seven native FDependency same-process object index events')
for entry in dep_records:
 def one_between(start,end=None):
  block=entry.split(start+'\n',1)[1]
  if end:block=block.split(end+'\n',1)[0]
  wordrows=hexlines(block)
  assert wordrows and wordrows[0][1]
  return wordrows[0][1][0]
 owner=one_between('NATIVE_OWNER_EXPORT','NATIVE_DEP_COUNT')
 count=one_between('NATIVE_DEP_COUNT','FIRST_REAL_DEPENDENCY_EXPORT')
 ids=[one_between('FIRST_REAL_DEPENDENCY_EXPORT','SECOND_REAL_DEPENDENCY_EXPORT' if count>1 else 'NATIVE_DEP_RECORD_DONE')]
 if count>1:ids.append(one_between('SECOND_REAL_DEPENDENCY_EXPORT','NATIVE_DEP_RECORD_DONE'))
 ok(owner not in dep_runtime and count==len(ids) and count in (1,2),
    'single authentic original native owner/dependency link')
 dep_runtime[owner]=ids
ok(sorted(dep_runtime)==sorted(rows),'all original same-process FDependency owners match real Core.u classes')
complete=0;dependency_count=0
for p in parts:
 p=p.split('NATIVE_CORE_CLASS_RECORD_FINISHED\n',1)[0]
 id=section(p,'ORIGINAL_CLASS_INDEX')[0]
 s=rows.get(id)
 if not s:continue
 friendly=section(p,'ORIGINAL_FRIENDLY_SCRIPT_LINE')
 masks=section(p,'ORIGINAL_MASKS_STATE_LABEL')
 guid=section(p,'ORIGINAL_CLASS_GUID')
 arrays=section(p,'ORIGINAL_DEPENDENCIES_AND_IMPORT_ARRAY')
 deps=section(p,'ORIGINAL_DEP_RECORDS')
 imports=section(p,'ORIGINAL_PACKAGE_IMPORT')
 ok(len(friendly)==6 and len(masks)==6 and len(guid)==4 and len(arrays)==6,
    'original compiled native scalar/GUID/arrays complete '+s['name'])
 if len(friendly)!=6 or len(masks)!=6 or len(guid)!=4 or len(arrays)!=6:continue
 ok(native_name(friendly[0])==s['friendly'],'native original FriendlyName real global FName '+s['name'])
 ok(friendly[2]==s['scriptSize'],'native original loaded Script.Num '+s['name'])
 for index,key in [(4,'textpos'),(5,'line')]:
  n=friendly[index];signed=n if n<0x80000000 else n-(1<<32)
  ok(signed==s[key],'native original source '+key+' '+s['name'])
 ok(struct.pack('<2I',*masks[:2]).hex()==s['probeMask'],
   'native original probe mask '+s['name'])
 ok(struct.pack('<2I',*masks[2:4]).hex()==s['ignoreMask'],
   'native original ignore mask '+s['name'])
 ok(masks[4]==s['stateFlags'] and (masks[5]&0xffff)==s['labelOffset'],
   'native original UState flags and label '+s['name'])
 ok(struct.pack('<4I',*guid).hex().upper()==s['ClassGuid'],
   'native original loaded ClassGuid 128 bits '+s['name'])
 dp,dn,dm,ip,ni,im=arrays
 ok(dn==len(s['deps']) and ni==len(s['imports']),'native source same class dependency/import counts '+s['name'])
 ok(dn<=dm and ni<=im and dp!=0 and ip!=0,
   'native fully initialized TArray class dependencies/imports '+s['name'])
 for j,dep in enumerate(s['deps']):
  ptr,deep,crc=deps[3*j:3*j+3]
  if len((ptr,deep,crc))!=3:raise AssertionError('Truncated actual native deps')
  ok(ptr!=0 and dep_runtime.get(id,[None]*len(s['deps']))[j]==dep['class_reference']-1,
    'original compiled same-process FDependency class UObject.Index '+s['name']+str(j))
  ok(deep==dep['deep'] and crc==dep['script_text_crc'],
    'native original FDependency deep/CRC '+s['name']+str(j))
  dependency_count+=1
 ok(len(s['imports'])==1 and native_name(imports[0])==s['imports'][0]['text'],
   'native original FName PackageImports '+s['name'])
 complete+=1
ok(complete==7,'all seven Core.u FULL native scalar/mask/GUID/dependency arrays accepted')
# Physical original file class Object original ClassFlags 274, 4 archive
# bytes (0x1552 -> 0x1556), not post-default temporary reset.
cursor=body('20261028-G3-UCLASS-v432-archive-cursor-original-core-fields.log')
ok('ORIGINAL_CLASSFLAGS_BEFORE_ARCHIVE\n' in cursor
 and 'ORIGINAL_CLASSFLAGS_AFTER_ARCHIVE\n' in cursor
 and '00001552' in cursor and '00001556' in cursor
 and '00000112' in cursor,'original FArchive actual Tell advance and source ClassFlags load')
ok(not errors,'all native/original G5 source validations pass')
out={'ok':not errors,'checks':checks,'failures':errors,
 'T013':'PASS' if not errors else 'BLOCKED',
 'original_stock_Core_u_UClass_fully_compared':complete,
 'native_Core_u_dependency_elements':dependency_count,
 'original_Engine_u_UClass_FULL_reused':88,
 'original_compiled_native_executions_by_this_verifier':0,
 'additional_original_469f_227i_227k_snapshots_preserved':'PARTIAL_NOT_ACCEPTED'}
print(json.dumps(out,indent=2))
sys.exit(0 if not errors else 1)
