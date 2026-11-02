class G3Array227Actor extends Actor;
struct G3Tuple
{
 var() int Number;
 var() float Fraction;
 var() name Label;
};
var() array<G3Tuple> Pairs;
var() array<string> Strings;
var() int StaticInts[2];
var() name StaticNames[2];
defaultproperties
{
 Pairs(0)=(Number=11,Fraction=1.5,Label=Alpha)
 Pairs(1)=(Number=-7,Fraction=-2.5,Label=Gamma)
 Strings(0)=""
 Strings(1)="Hello-227"
 StaticInts(0)=123
 StaticInts(1)=-456
 StaticNames(0)=Alpha
 StaticNames(1)=Beta
}
