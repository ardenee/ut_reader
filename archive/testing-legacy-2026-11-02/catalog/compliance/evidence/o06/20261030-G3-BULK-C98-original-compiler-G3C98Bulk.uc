class G3C98Bulk expands Actor config localized;
struct G3Base
{
    var int BX;
    var float BY;
};
struct G3Child expands G3Base
{
    var byte CZ;
};
var int G3Ints[2];
var byte G3ByteZero, G3ByteMax;
var int G3Neg, G3Max;
var float G3FloatNeg, G3FloatZero, G3FloatFrac;
var bool bG3A, bG3B;
var name G3Name, G3None;
var G3Child G3Struct;
var transient int G3Transient;
var config int G3Config;
var localized string[32] G3Local;
defaultproperties
{
    G3Ints(0)=7
    G3Ints(1)=-9
    G3ByteMax=255
    G3Neg=-2147483647
    G3Max=2147483647
    G3FloatNeg=-1.5
    G3FloatZero=0
    G3FloatFrac=0.375
    bG3A=True
    G3Name=G3BulkName
    G3Struct=(BX=789,BY=2.5,CZ=17)
    G3Transient=333
    G3Config=444
    G3Local="DefaultBulk"
}
