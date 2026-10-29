#!/usr/bin/env python3
"""Frozen G3 T049 original compiled Unreal 227i/227k UArrayProperty network/Should.
Actual native Core.dll semantics; archive-only UnrealDB source gate refuses to
synthesize unsupported network/runtime instance encoders. No engine reruns.
"""
from pathlib import Path
import hashlib,json,re,sys,pefile
repo=Path(__file__).resolve().parents[2]
ev=repo/'catalog/compliance/evidence/o06'
c227i=Path(r'C:\Temp\UE1-G3-NativeOracle\original227i\System\Core.dll')
c227k=Path(r'C:\Temp\UnrealDB\O06\Native-227k\System\Core.dll')
original_engine=Path(r'C:\Temp\UnrealDB\O06\Native-227k\System\Engine.u')
sdk2012=Path(r'L:\Source\Games\Unreal\Unreal [v1.227]\Core\Inc\UnType.h')
sdk2026=Path(r'L:\Source\Games\Unreal\OldUnreal-UnrealPatch227k-SDK-Windows\Core\Inc\UnType.h')
nativeNet=ev/'20261029-G3-T049-C227k-original-24-NetSerialize-8-real-int-arrays.log'
nativeShould=ev/'20261029-G3-T049-C227k-ArrayProperty-original-native.log'
probe=ev/'20261029-G3-T049-C227k-original-NetSerialize-probe.cpp'
reader=repo/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php'
checks=0;bad=[]
def verify(cond,msg):
 global checks
 checks+=1
 if not cond:bad.append(msg)
def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
for p,h in [
 (c227i,'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'),
 (c227k,'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'),
 (original_engine,'a1e2378d78851b155af7408d7c576c919e334fcff35d347a560776d14b58563e'),
 (sdk2012,'b7faf265555ee3c05a03368826613ad68ea5f1bc90076451b908c44b7b77cf69'),
 (sdk2026,'2c78a2c6403ea68f3cc59d7e9d56005c740293aaa8ac22703b1dcef2fcb9543e'),
 (nativeNet,'b1974ebaf64ae972fa4f12ee06f7a349c2fd2bd017c575c168e8e88eee1c5343'),
 (nativeShould,'8b8cad0ad940ed5a55b6b805b4e88a0fc1fd861e3709208b50423fc050f480a4'),
 (probe,'0c42c3dc5c2949babb3b289e5aa838d746e28af7795d1d747c50d0f37c9db6dd')]:
 verify(p.exists() and sha(p)==h,'Original compiled native/source SHA-256 '+str(p))
old=pefile.PE(str(c227i));new=pefile.PE(str(c227k))
def locate(p,method):
 return [e.address for e in p.DIRECTORY_ENTRY_EXPORT.symbols if e.name and method.encode() in e.name]
oldN=locate(old,'NetSerializeItem@UArrayProperty')
newN=locate(new,'NetSerializeItem@UArrayProperty')
oldS=locate(old,'ShouldSerializeItem@UArrayProperty')
newS=locate(new,'ShouldSerializeItem@UArrayProperty')
verify(oldN==[0x49fe0] and old.get_data(0x49fe0,8)==bytes.fromhex('b801000000c20c00'),
       'Original 2012 UArrayProperty NetSerializeItem returns TRUE without writing (native x86 mov eax,1; ret 0xc)')
verify(newN==[0x7e8b0] and newS==[0x81440],'Original 2026 C227k independently compiled NetSerializeItem/ShouldSerializeItem')
verify(oldS==[], 'Original 2012 UArrayProperty ShouldSerializeItem has no named export; runtime call remains unverified')
# Read the TWO SDK UArrayProperty declarations, not cross-matching other classes.
def arr_decl(src):
 t=src.read_text(encoding='latin1')
 i=t.index('class CORE_API UArrayProperty : public UProperty')
 return t[i:t.index('};',i)]
oldDecl=arr_decl(sdk2012)
newDecl=arr_decl(sdk2026)
verify('NetSerializeItem(' in oldDecl and 'ShouldSerializeItem(' in oldDecl,
       'Original 2012 UArrayProperty SDK declares both Net and ShouldSerialize methods')
verify('NetSerializeItem(' in newDecl and 'ShouldSerializeItem(' in newDecl,
       'Original 2026 SDK source explicitly adds conditional ShouldSerializeItem override')
# Native actual 2026 Engine loaded, 24 independent UArrayProperty::NetSerializeItem
# dispatches, real original UIntProperty inner, original FBufferArchive writes.
s=nativeNet.read_bytes().decode('utf-8','replace')
verify('G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=' in s and 'native_error=0' in s,
       'Native original 2026 loaded genuine Engine.u successfully before calls')
pattern=r'^G3_T049_ORIGINAL_227K_INT_NET name=ArrayProperty (Engine\.[^|\r\n]+)\|case=(\d)\|result=(\d)\|output_size=(\d+)\|output_hex=([A-F0-9]*)\r?$'
matches=re.findall(pattern,s,re.M)
verify(len(matches)==24,'24 actual native NetSerializeItem return values/bytes for 8 real class properties')
by={}
payloads={0:'0000',1:'0100000000000B000000',2:'0200000000000B00000003000000F9FFFFFF'}
for name,case,rc,nbytes,raw in matches:
 ci=int(case);by.setdefault(name,set()).add(ci)
 verify(ci in payloads and rc=='1' and raw==payloads.get(ci)
        and int(nbytes)==len(raw)//2,
        f'Original 2026 native UArray {name} {ci} exact result/serial byte count')
verify(len(by)==8 and all(v=={0,1,2} for v in by.values()),
       '8 unique original real Engine.u UArray Int-inner classes each exercised empty, one and two')
# True 2026 ShouldSerializeItem: 16 object-inner properties x two 12-byte
# FArray slots; 227i declared override remains runtime-unverified, not invented.
should=nativeShould.read_text(encoding='utf-8',errors='replace')
spat=r'^G3_T049_ORIGINAL_227K_ARRAY_SHOULD_SERIALIZE name=(.*?) null=(\d) nonnull=(\d) innerKind=(\d+) num=(\d+)'
smatches=re.findall(spat,should,re.M)
verify(len(smatches)==16,'16 original compiled ShouldSerializeItem actual engine properties')
for name,null,nonnull,inner,num in smatches:
 verify(null=='1' and nonnull=='1' and inner=='5' and num=='1',
        'Original 227k ShouldSerializeItem TRUE from both valid native FArray slots '+name)
# Production code is a READ-ONLY loader for on-disk UProperty::Serialize;
# it must NOT invent runtime NetSerializeItem result or pretend to save network.
body=reader.read_text(encoding='utf-8')
verify('This decodes the on-disk LOAD/SAVE archive only.' in body
       and 'does NOT execute or implement runtime instance encoders.' in body,
       'UnrealDB explicitly excludes runtime instance/network encoders; archive-only parity scope')
verify(not re.search(r'function\s+(?:NetSerializeItem|ShouldSerializeItem)\s*\(',body),
       'No fabricated runtime network/ShouldSerializeItem codec in current original file reader')
result={'ok':not bad,'checks':checks,'failures':bad,
 'fixed_test':'T049','status':'PARTIAL_ONLY' if not bad else 'BLOCKED',
 'original_2012_native_NetSerializeItem':{'return':1,'serialized_bytes':0,'named_PE_export_ShouldSerializeItem':False,'original_compiled_ShouldSerializeItem_runtime':'UNVERIFIED'},
 'original_2026_native_NetSerializeItem':{'real_Engine_IntArray_properties':len(by),'native_successful_calls':len(matches),
   'cases':[{'case':i,'length':len(h)//2,'hex':h} for i,h in payloads.items()]},
 'original_2026_native_ShouldSerializeItem':{'real_Engine_object_array_properties':len(smatches),'native_boolean_calls':32},
 'reader_network_scope':'runtime instance network codecs explicitly UNSUPPORTED; only package archive metadata decoded',
 'original_compiled_native_sessions_rerun_by_verifier':0}
print(json.dumps(result,indent=2,ensure_ascii=False))
sys.exit(0 if not bad else 1)
