class G3Script227Actor extends Actor;

struct G3ScriptTuple
{
    var int Amount;
    var name NameValue;
    var vector Point;
};
var G3ScriptTuple Tuple;

function int ProbeMixedObjectNameVector()
{
    local name N;
    local class<Actor> TargetClass;
    local vector Point;
    N='G3ActualName';
    TargetClass=class'Engine.Actor';
    Point=vect(3.25,-4.5,5.75);
    if(N=='G3ActualName' && TargetClass==class'Engine.Actor')
        return int(Point.X);
    return 0;
}
function name ProbeNameReturn()
{
    return 'G3ActualName';
}
function class<Actor> ProbeClassReturn()
{
    return class'Engine.Actor';
}
defaultproperties
{
 Tuple=(Amount=33,NameValue=G3ActualName,Point=(X=3.25,Y=-4.5,Z=5.75))
}