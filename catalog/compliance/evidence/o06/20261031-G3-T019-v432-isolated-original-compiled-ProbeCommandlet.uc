class G3BulkConfigV432ProbeCommandlet expands Commandlet;
function int Main(string Params)
{
 log("T019_ORIGINAL_V432_START");
 log("T019_ORIGINAL_V432_CONFIG=" $ class'G3BulkConfigV432Actor'.default.ConfigValue);
 log("T019_ORIGINAL_V432_LOCALIZED=" $ class'G3BulkConfigV432Actor'.default.LocalMessage);
 log("T019_ORIGINAL_V432_TRANSIENT=" $ class'G3BulkConfigV432Actor'.default.ScratchValue);
 log("T019_ORIGINAL_V432_PERSIST=" $ class'G3BulkConfigV432Actor'.default.PersistValue);
 log("T019_ORIGINAL_V432_DONE");
 return 0;
}
defaultproperties
{
 HelpCmd=G3BulkConfigV432Probe
 HelpOneLiner="Original compiled UT99 class default precedence oracle"
 LogToStdout=True
 IsServer=False
 IsClient=False
 IsEditor=True
}
