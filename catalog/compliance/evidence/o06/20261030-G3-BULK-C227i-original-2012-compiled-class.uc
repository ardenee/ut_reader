class G3Bulk227Actor extends Actor;
var() array<int> Numbers;
var() array<name> Labels;
var() array<Actor> References;
var() array<string> Strings;
var() int BigNeg;
var() byte MaxByte;
var() float NegFloat;
var() bool bTrueValue;
var() bool bFalseValue;
var() name NonZeroName;
var() name NoneName;
defaultproperties
{
    Numbers(0)=11
    Numbers(1)=-7
    Labels(0)=Alpha
    Labels(1)=Beta
    BigNeg=-2147483647
    MaxByte=255
    NegFloat=-1.5
    bTrueValue=True
    NonZeroName=Alpha
}
