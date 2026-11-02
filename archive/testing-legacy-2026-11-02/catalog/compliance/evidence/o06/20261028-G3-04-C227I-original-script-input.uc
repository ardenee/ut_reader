class G3042012Actor extends Actor;

var int ExampleValue;

function int G304AddOne(int X)
{
    return X+1;
}

state G304NativeState
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