class G3T074LoaderCommandlet extends Commandlet;
event int Main(string Parms)
{
    local Object Target;
    Target=DynamicLoadObject("Core.Subsystem",class'Object',True);
    Log("T074_DYN_Core_Subsystem=" $ String(Target));
    Target=DynamicLoadObject("Core.Time",class'Object',True);
    Log("T074_DYN_Core_Time=" $ String(Target));
    Target=DynamicLoadObject("Core.Locale",class'Object',True);
    Log("T074_DYN_Core_Locale=" $ String(Target));
    Target=DynamicLoadObject("Core.HelloWorldCommandlet",class'Object',True);
    Log("T074_DYN_Core_HelloWorldCommandlet=" $ String(Target));
    Target=DynamicLoadObject("Core.HelloWorldCommandlet.Main",class'Object',True);
    Log("T074_DYN_Core_HelloWorld_Main=" $ String(Target));
    Target=DynamicLoadObject("Core.HelloWorldCommandlet.strparm",class'Object',True);
    Log("T074_DYN_Core_strparm=" $ String(Target));
    Target=DynamicLoadObject("Core.HelloWorldCommandlet.intparm",class'Object',True);
    Log("T074_DYN_Core_intparm=" $ String(Target));
    Target=DynamicLoadObject("Core.HelloWorldCommandlet.Main.Parms",class'Object',True);
    Log("T074_DYN_Core_Parms=" $ String(Target));
    Target=DynamicLoadObject("Core.Subsystem.ExecVtbl",class'Object',True);
    Log("T074_DYN_Core_ExecVtbl=" $ String(Target));
    Target=DynamicLoadObject("Core.HelloWorldCommandlet.Main.ReturnValue",class'Object',True);
    Log("T074_DYN_Core_ReturnValue=" $ String(Target));
    return 0;
}
defaultproperties { LogToStdout=True IsEditor=True LazyLoad=False ShowBanner=False }
