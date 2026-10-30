class G3Fixed227Actor extends Actor;
struct G3PlainPair
{
 var() int Number;
 var() float Fraction;
};
var() array<G3PlainPair> PlainPairs;
var() int TwoInts[2];
defaultproperties
{
 PlainPairs(0)=(Number=101,Fraction=1.25)
 PlainPairs(1)=(Number=-202,Fraction=-2.75)
 TwoInts(0)=123
 TwoInts(1)=-456
}
