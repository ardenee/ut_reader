class G304C98Actor expands Actor;

var int ExampleValue;
function int G304AddOne(int X)
{
    return X+1;
}
state G304State
{
    function bool G304CanJump()
    {
        return true;
    }
Begin:
    Sleep(0.2);
}
defaultproperties
{
    ExampleValue=4
}