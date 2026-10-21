// Isolated ABI probe transcribing ORIGINAL Epic v0.84a field declarations.
// Sources: Src/Inc/Root.h pack(push,4), UnPort.h, UnMath.h,
// UnRes.h; compile with MSVC Hostx64/x86 /c, no Epic binary required.
// This verifies *compiler layout*, NOT original Unreal runtime loading.
#pragma pack(push,4)
typedef unsigned char BYTE;
typedef unsigned short WORD;
typedef short SWORD;
typedef unsigned int DWORD;
typedef int INT;
typedef int INDEX;
typedef float FLOAT;
typedef unsigned __int64 QWORD;
struct FVector {
 union {struct {FLOAT X,Y,Z;};struct {FLOAT R,G,B;};FLOAT V[3];};
 union {struct {DWORD iTransform:16;DWORD Flags:8;DWORD Align:8;};FLOAT W;};
};
struct FRotation {INT Pitch,Yaw,Roll;};
struct FBoundingRect {FVector Min,Max;};
struct FMeshUV {BYTE U,V;};
struct FMeshVertex {INT X:11;INT Y:11;INT Z:10;};
struct FMeshTriangle {
 WORD iVertex[3];BYTE Type;BYTE Color;
 FMeshUV Tex[3];BYTE TextureNum;BYTE Flags;
};
struct FMeshVertLinkIndex {INT NumVertTriangles;INT TriangleListOffset;};
struct FMeshAnimSeq {WORD SeqName;INT SeqStartFrame,SeqNumFrames,Rate;};
struct FVertPool {INDEX pVertex;INDEX iSide;};
struct FTerrainIndex {INT GridX0,GridX1,GridY0,GridY1;};
struct FZoneProperties {
 INDEX iZoneActor; INDEX Unused;
 QWORD Connectivity;QWORD Visibility;
};
struct FBspNode {
 FVector Plane;QWORD ZoneMask;
 INDEX NodeFlags,iVertPool,iSurf;
 union {struct {INDEX iBack,iFront,iPlane;};INDEX iChild[3];};
 INDEX iBound;BYTE iZone[2],NumVertices,Pad1;
 INDEX iDynamic[2];
};
struct FBspSurf {
 DWORD Texture,Brush,PolyFlags;
 INDEX pBase,vNormal,vTextureU,vTextureV,iLightMesh,iBrushPoly;
 BYTE PanU,PanV;INDEX iActor;SWORD LastStartY,LastEndY;
};
struct UBspNodesExtension {
 DWORD NumZones,NumUniquePlanes,Unused1,Unused2;
 FZoneProperties Zones[64];
};
#pragma pack(pop)
static_assert(sizeof(FVector)==16,"1996 FVector is 16 not 12");
static_assert(sizeof(FBoundingRect)==32,"1996 bounding rect has two full FVector");
static_assert(sizeof(FRotation)==12,"1996 angle rotation signed INT32 x3");
static_assert(sizeof(FMeshVertex)==4,"packed 11/11/10 bit mesh vertex");
static_assert(sizeof(FMeshTriangle)==16,"source mesh triangle");
static_assert(sizeof(FMeshVertLinkIndex)==8,"mesh vertex link index");
static_assert(sizeof(FMeshAnimSeq)==16,"FName WORD plus padding and 3 INT");
static_assert(sizeof(FVertPool)==8,"two signed INDEX");
static_assert(sizeof(FTerrainIndex)==16,"four signed INT");
static_assert(sizeof(FZoneProperties)==24,"packed4 1996 zone properties");
static_assert(sizeof(FBspNode)==64,"source FBspNode is 64 bytes");
static_assert(sizeof(FBspSurf)==48,"source FBspSurf record is 48 bytes");
static_assert(sizeof(UBspNodesExtension)==1552,"zone table header extension");
static_assert(sizeof(UBspNodesExtension)+84==1636,"UBspNodes total resource header");
static_assert(sizeof(FVector)+sizeof(FRotation)+sizeof(FBoundingRect)+40+76==176,"UMesh header");
static_assert(sizeof(FVector)+16+76==108,"UMeshMap header");
