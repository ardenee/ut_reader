#include <windows.h>
#include <cstdio>
#include <cstdlib>
#include <cstdint>
#include <cstring>
struct MockArchive {
    uintptr_t* methods;
    unsigned char memory[32];
    int position;
    int limit;
    int calls_serialize;
    int calls_tell;
    int calls_seek;
    int last_read_bytes;
    int error;
};
static int __fastcall Tell(MockArchive* a, void*) {
    a->calls_tell++;
    return a->position;
}
static void __fastcall Seek(MockArchive* a, void*, int offset) {
    a->calls_seek++;
    if (offset < 0 || offset > a->limit) {a->error = 1; return;}
    a->position = offset;
}
static MockArchive* __fastcall ArchiveFName(MockArchive* ar,void*,void* target){
    // ABI of genuine original FArchive::operator<<(FName&): returns
    // FArchive&. Value is a 4-byte pointer-like FName* in 227k Any.
    ar->calls_serialize++;
    if(ar->position+4>ar->limit){ar->error=3;return ar;}
    if(ar->memory[31]==0xCE)memcpy(target,ar->memory+ar->position,4);
    else memcpy(ar->memory+ar->position,target,4);
    ar->position+=4;
    return ar;
}
static MockArchive* __fastcall ArchiveObjectReference(MockArchive* ar,void*,void* target){
    // Genuine compiled FAnyProperty's virtual FArchive << UObject*
    // dispatched through archive vtable slot +0x18.
    ar->calls_serialize++;
    if(ar->position+4>ar->limit){ar->error=3;return ar;}
    if(ar->memory[31]==0xCE)memcpy(target,ar->memory+ar->position,4);
    else memcpy(ar->memory+ar->position,target,4);
    ar->position+=4;
    return ar;
}
static void __fastcall CountBytes(MockArchive*,void*,int,int) {
    // Original C227k FString::operator<< FArchive virtual slot +0x14:
    // CountBytes(in-memory bytes, max allocated bytes). No data transfer.
}
static void __fastcall Serialize(MockArchive* a,void*,void* destination,int length) {
    a->calls_serialize++;
    a->last_read_bytes = length;
    if(length<0 || a->position + length > a->limit) {
        a->error=2; return;
    }
    if(destination!=nullptr && length) {
        if(a->memory[31] == 0xCE) {
            memcpy(destination,a->memory+a->position,length);
        } else {
            memcpy(a->memory+a->position,destination,length);
        }
    }
    a->position +=length;
}
struct MockAny {
    unsigned char* Data;
    void* StructType;
    int ReferenceCount;
    unsigned char DataType;
    unsigned char padding[3];
};
static void assert_eq(const char* label, int expected, int found) {
    if(expected!=found){
        fprintf(stderr,"FAIL %s expected=%d actual=%d\n",label,expected,found);
        exit(2);
    }
    printf("PASS %s=%d\n",label,found);
}
int main(int argc, char** argv) {
    const char* dll = "L:\\Source\\Games\\Unreal\\OldUnreal-UnrealPatch227k-Windows\\System\\Core.dll";
    HMODULE core=LoadLibraryA(dll);
    if(!core) {fprintf(stderr,"FAIL LoadLibrary Core.dll winerror=%lu\n",GetLastError());return 1;}
    typedef int (__cdecl *Peek)(MockArchive*);
    typedef void (__cdecl *Empty)(MockArchive*);
    Peek peek=reinterpret_cast<Peek>(GetProcAddress(core,"?PeekIsEmpty@FAnyProperty@@SAHAAVFArchive@@@Z"));
    Empty empty=reinterpret_cast<Empty>(GetProcAddress(core,"?SerializeEmpty@FAnyProperty@@SAXAAVFArchive@@@Z"));
    if(!peek||!empty) {fprintf(stderr,"FAIL original compiled FAnyProperty symbols not found\n");return 1;}
    void** originalGMallocData=reinterpret_cast<void**>(
      GetProcAddress(core,"?GMalloc@@3PAVFMalloc@@A"));
    printf("ORIGINAL_NATIVE_GMalloc_GLOBAL_address=%p original_value=%p\\n",
      originalGMallocData,originalGMallocData?*originalGMallocData:nullptr);
    printf("ORIGINAL_SHIPPED_CORE_DLL=%s\n",dll);
    printf("ORIGINAL_COMPILED_PeekIsEmpty=%p SerializeEmpty=%p\n",
           reinterpret_cast<void*>(peek),reinterpret_cast<void*>(empty));
    uintptr_t methods[24]={};
    methods[1]=reinterpret_cast<uintptr_t>(&Serialize);
    methods[5]=reinterpret_cast<uintptr_t>(&CountBytes);
    methods[7]=reinterpret_cast<uintptr_t>(&ArchiveFName);
    methods[6]=reinterpret_cast<uintptr_t>(&ArchiveObjectReference);
    methods[13]=reinterpret_cast<uintptr_t>(&Tell);
    methods[17]=reinterpret_cast<uintptr_t>(&Seek);
    MockArchive ar{};
    ar.methods=methods;

    if(argc>1 && atoi(argv[1])==7) {
       typedef void (__thiscall *ItemAny)(void*,MockArchive*,void*);
       ItemAny original=(ItemAny)GetProcAddress(core,"?SerializeItem@UAnyProperty@@UBEXAAVFArchive@@PAX@Z");
       int anyClass=0;MockAny* value=nullptr;
       memset(ar.memory,0,sizeof(ar.memory));
       ar.memory[0]=7;
       ar.memory[12]=1; ar.memory[31]=0xCE;
       ar.position=0;ar.limit=28;
       printf("ORIGINAL_227K_ANY_OBJECT7_BEFORE_LOAD type7 ZERO-OBJ-REF\n");fflush(stdout);
       original(&anyClass,&ar,&value);
       printf("ORIGINAL_227K_ANY_OBJECT7_AFTER_LOAD pointer=%p type=%d cursor=%d calls=%d error=%d dataPointer=%p\n",
              value,value?value->DataType:-1,ar.position,ar.calls_serialize,ar.error,value?value->Data:nullptr);fflush(stdout);
       if(!value || value->DataType!=7 || ar.error || ar.position!=5)return 31;
       memset(ar.memory,0,sizeof(ar.memory));
       ar.memory[12]=2; ar.memory[31]=0xEF;
       ar.position=0;ar.limit=28;
       ar.calls_serialize=ar.calls_seek=ar.calls_tell=ar.error=0;
       original(&anyClass,&ar,&value);
       printf("ORIGINAL_227K_ANY_OBJECT7_AFTER_SAVE cursor=%d calls=%d error=%d hex=",
             ar.position,ar.calls_serialize,ar.error);
       for(int i=0;i<ar.position;i++)printf("%02x",ar.memory[i]);puts("");
       if(ar.error || ar.position!=5 || ar.memory[0]!=7)return 32;
       return 0;
    }
    if(argc>1) {
       int target=atoi(argv[1]);
       if(target<1||target>3) {fprintf(stderr,"FAIL selected subtype must be 1..3\\n");return 9;}
       typedef void (__thiscall *ItemAny)(void*,MockArchive*,void*);
       ItemAny loadNative=reinterpret_cast<ItemAny>(GetProcAddress(
         core,"?SerializeItem@UAnyProperty@@UBEXAAVFArchive@@PAX@Z"));
       if(!loadNative){fprintf(stderr,"FAIL native UAnyProperty::SerializeItem lookup\\n");return 8;}
       MockAny* value=nullptr;
       int anyProperty=0;
       memset(ar.memory,0,sizeof(ar.memory));
       ar.memory[0]=static_cast<unsigned char>(target);
       if(target==3){ar.memory[1]=1;}
       else{ar.memory[1]=0x78;ar.memory[2]=0x56;ar.memory[3]=0x34;ar.memory[4]=0x12;}
       ar.memory[12]=1;
       ar.memory[31]=0xCE;
       ar.limit=16;
       ar.position=0;
       ar.calls_tell=ar.calls_seek=ar.calls_serialize=ar.error=0;
       loadNative(&anyProperty,&ar,&value);
       int size=target==3?2:5;
       printf("NATIVE_ORIGINAL_227K_UAny_SUBTYPE=%d LOADED_PTR=%p TYPE=%u,VALUE_PTR=%p,VALUE_RAW4=%08x,CURSOR=%d,SERIALIZE_CALLS=%d,ERR=%d\\n",
          target,value,value?value->DataType:255,value?value->Data:nullptr,
          value&&value->Data?*(uint32_t*)(value->Data):0,
          ar.position,ar.calls_serialize,ar.error);
       assert_eq("native_nonnull_loaded_pointer",1,value!=nullptr);
       assert_eq("native_loaded_exact_runtime_ANYTYPE",target,value->DataType);
       assert_eq("native_loaded_expected_bytes",size,ar.position);
       assert_eq("native_loaded_no_Archive_error",0,ar.error);
       if(target==3)assert_eq("native_BOOL_value",1,value->Data?*(uint32_t*)value->Data:0);
       else assert_eq("native_INT_or_FLOAT_bits",0x12345678,value->Data?*(uint32_t*)value->Data:0);
       memset(ar.memory,0,sizeof(ar.memory));
       ar.memory[31]=0xEF;
       ar.position=0;ar.limit=16;ar.calls_serialize=ar.error=0;
       loadNative(&anyProperty,&ar,&value);
       assert_eq("native_SAVE_valid_type",target,ar.memory[0]);
       assert_eq("native_SAVE_serialized_size",size,ar.position);
       assert_eq("native_SAVE_no_archive_error",0,ar.error);
       if(target==3)assert_eq("native_SAVE_BOOL_byte",1,ar.memory[1]);
       else {
        assert_eq("native_SAVE_value_b0",0x78,ar.memory[1]);
        assert_eq("native_SAVE_value_b1",0x56,ar.memory[2]);
        assert_eq("native_SAVE_value_b2",0x34,ar.memory[3]);
        assert_eq("native_SAVE_value_b3",0x12,ar.memory[4]);
       }
       printf("NATIVE_227K_UAny_SUBTYPE_%d_REAL_COMPILED_LOAD_SAVE_PASS=1\\n",target);
       return 0;
    }
    for(int type=0;type<=7;type++) {
       memset(ar.memory,0,sizeof(ar.memory));
       ar.memory[0]=0x7d;ar.memory[1]=static_cast<unsigned char>(type);
       ar.memory[31]=0xCE;
       ar.position=1;ar.limit=16;
       ar.calls_serialize=ar.calls_tell=ar.calls_seek=ar.error=0;
       int isEmpty=peek(&ar);
       char label[140];
       sprintf_s(label,"original_compiled_FAny_PeekIsEmpty_type_%d",type);
       assert_eq(label,type==0?1:0,isEmpty);
       assert_eq("native_archive_saved_cursor_unchanged",1,ar.position);
       assert_eq("native_calls_Tell",1,ar.calls_tell);
       assert_eq("native_calls_ReadOneByte",1,ar.calls_serialize);
       assert_eq("native_reads_one_byte",1,ar.last_read_bytes);
       assert_eq("native_calls_SeekBack",1,ar.calls_seek);
       assert_eq("native_no_archive_error",0,ar.error);
    }
    memset(ar.memory,0,sizeof(ar.memory));
    ar.position=3;ar.limit=16;
    ar.calls_serialize=ar.calls_tell=ar.calls_seek=ar.error=0;
    ar.memory[31]=0xEF;
    empty(&ar);
    assert_eq("original_compiled_SerializeEmpty_written_byte_zero",0,ar.memory[3]);
    assert_eq("original_compiled_SerializeEmpty_cursor_advanced_one",4,ar.position);
    assert_eq("original_compiled_SerializeEmpty_single_serialize_call",1,ar.calls_serialize);
    assert_eq("original_compiled_SerializeEmpty_no_seek",0,ar.calls_seek);
    assert_eq("original_compiled_SerializeEmpty_no_Tell",0,ar.calls_tell);
    assert_eq("original_compiled_SerializeEmpty_no_archive_error",0,ar.error);
    typedef void (__thiscall *SerializeAny)(MockAny*,MockArchive*);
    SerializeAny originalSerialize=reinterpret_cast<SerializeAny>(
      GetProcAddress(core,"?Serialize@FAnyProperty@@QAEXAAVFArchive@@@Z"));
    if (!originalSerialize) { fprintf(stderr,"FAIL original compiled FAny Serialize export absent\\n");return 1;}
    printf("ORIGINAL_COMPILED_FAnyProperty_Serialize=%p\n",reinterpret_cast<void*>(originalSerialize));
    static_assert(sizeof(MockAny)==16, "Original SDK FAnyProperty must occupy exactly 16 x86 bytes");
    MockAny obj{};
    obj.ReferenceCount=1;
    obj.DataType=0;
    memset(ar.memory,0,sizeof(ar.memory));
    ar.memory[12]=1;  // In this controlled FArchive ABI, IsLoading flag at archive+0x10.
    ar.memory[31]=0xCE;
    ar.limit=16;ar.position=0;ar.calls_serialize=ar.calls_seek=ar.calls_tell=ar.error=0;
    originalSerialize(&obj,&ar);
    assert_eq("native_original_FAny_Serialize_LOAD_NULL_type",0,obj.DataType);
    assert_eq("native_original_FAny_Serialize_LOAD_NULL_archive_bytes",1,ar.position);
    assert_eq("native_original_FAny_Serialize_LOAD_NULL_Serialize_calls",1,ar.calls_serialize);
    assert_eq("native_original_FAny_Serialize_LOAD_NULL_error",0,ar.error);
    obj.DataType=0;ar.memory[12]=0;ar.memory[31]=0xEF;ar.memory[0]=0x89;
    ar.position=0;ar.calls_serialize=ar.calls_seek=ar.calls_tell=ar.error=0;
    originalSerialize(&obj,&ar);
    assert_eq("native_original_FAny_Serialize_SAVE_NULL_written_type",0,ar.memory[0]);
    assert_eq("native_original_FAny_Serialize_SAVE_NULL_written_bytes",1,ar.position);
    assert_eq("native_original_FAny_Serialize_SAVE_NULL_error",0,ar.error);
    typedef void (__thiscall *ItemAny)(void*,MockArchive*,void*);
    ItemAny originalItem=reinterpret_cast<ItemAny>(
      GetProcAddress(core,"?SerializeItem@UAnyProperty@@UBEXAAVFArchive@@PAX@Z"));
    if(!originalItem){fprintf(stderr,"FAIL original compiled UAnyProperty SerializeItem not exported\\n");return 1;}
    int mockUAnyInstance=0;
    MockAny* instanceValue=nullptr;
    memset(ar.memory,0,sizeof(ar.memory));
    ar.memory[12]=1; // original FArchive::ArIsLoading
    ar.memory[31]=0xCE;
    ar.position=0;ar.limit=16;
    ar.calls_serialize=ar.calls_tell=ar.calls_seek=ar.error=0;
    originalItem(&mockUAnyInstance,&ar,&instanceValue);
    assert_eq("native_UAnyProperty_SerializeItem_LOAD_EMPTY_preserves_null_instance",
      1,instanceValue==nullptr);
    assert_eq("native_UAnyProperty_SerializeItem_LOAD_EMPTY_consumed_one_type_byte",1,ar.position);
    assert_eq("native_UAnyProperty_SerializeItem_LOAD_EMPTY_serializes_twice",2,ar.calls_serialize);
    assert_eq("native_UAnyProperty_SerializeItem_LOAD_EMPTY_Tell_once",1,ar.calls_tell);
    assert_eq("native_UAnyProperty_SerializeItem_LOAD_EMPTY_Seek_back_once",1,ar.calls_seek);
    assert_eq("native_UAnyProperty_SerializeItem_LOAD_EMPTY_no_error",0,ar.error);
    memset(ar.memory,0,sizeof(ar.memory));
    ar.memory[0]=0x89;
    ar.memory[12]=0;
    ar.memory[31]=0xEF;
    ar.position=0;ar.limit=16;
    ar.calls_serialize=ar.calls_tell=ar.calls_seek=ar.error=0;
    originalItem(&mockUAnyInstance,&ar,&instanceValue);
    assert_eq("native_UAnyProperty_SerializeItem_SAVE_NULL_writes_type_zero",0,ar.memory[0]);
    assert_eq("native_UAnyProperty_SerializeItem_SAVE_NULL_consumed_one_byte",1,ar.position);
    assert_eq("native_UAnyProperty_SerializeItem_SAVE_NULL_one_serialize",1,ar.calls_serialize);
    assert_eq("native_UAnyProperty_SerializeItem_SAVE_NULL_no_cursor_peek",0,ar.calls_tell);
    assert_eq("native_UAnyProperty_SerializeItem_SAVE_NULL_no_error",0,ar.error);
    printf("ORIGINAL_227K_NATIVE_FANY_TYPE_ORACLE_ALL_PASS=1\n");
    return 0;
}
