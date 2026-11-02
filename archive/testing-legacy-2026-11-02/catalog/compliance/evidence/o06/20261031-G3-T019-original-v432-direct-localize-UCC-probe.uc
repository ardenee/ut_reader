class G3V432LocaleProbeCommandlet extends Commandlet;
function int Main(string Params) {
 log("G3_T019_V432_NOEDITOR_START");
 log("G3_T019_V432_DIRECT_LOCALIZE=" $ Localize("G3BulkConfigV432Actor","LocalMessage","G3BulkConfigV432"));
 log("G3_T019_V432_NOEDITOR_CONFIG=" $ class'G3BulkConfigV432.G3BulkConfigV432Actor'.default.ConfigValue);
 log("G3_T019_V432_NOEDITOR_LOCALIZED=" $ class'G3BulkConfigV432.G3BulkConfigV432Actor'.default.LocalMessage);
 log("G3_T019_V432_NOEDITOR_TRANSIENT=" $ class'G3BulkConfigV432.G3BulkConfigV432Actor'.default.ScratchValue);
 log("G3_T019_V432_NOEDITOR_PERSIST=" $ class'G3BulkConfigV432.G3BulkConfigV432Actor'.default.PersistValue);
 return 0;
}
defaultproperties {
 HelpCmd=G3V432LocaleProbe
 LogToStdout=True
 IsServer=False
 IsClient=False
 IsEditor=False
}
