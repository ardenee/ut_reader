$ErrorActionPreference='Stop'
python C:\Temp\UnrealDB\O06\prepare-G3-B08-T064-three-original-malformed-tags.py
Set-Location C:\Temp\UnrealDB\O06\Native-227k\System
foreach($suffix in @('BadTagType','BadArrayIndex','BadTerminator')){
 $log='C:\Temp\UnrealDB\O06\G3-B08-T064-original-227k-'+$suffix+'.log'
 & .\G3-B06-C227k-original-native-malformed-array-load.exe ('G3Array227Runtime'+$suffix+'.u') *> $log
 Write-Output ('ORIGINAL_NATIVE_T064_'+$suffix+' exit='+$LASTEXITCODE)
 Select-String -Path $log -Pattern 'G3_B06_NEGATIVE','Bad name index','bad=','History:','Invalid','size mismatch','RELOADED_COUNT','Error' | Select-Object -Last 12 | ForEach-Object {$_.Line}
}
