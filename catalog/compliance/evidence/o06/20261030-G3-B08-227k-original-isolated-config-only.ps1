$ErrorActionPreference='Stop'
$root='C:\Temp\UnrealDB\O06'
$sys=Join-Path $root 'Native-227k\System'
$userini=Join-Path $sys 'User.ini'
$original=[IO.File]::ReadAllBytes($userini)
Set-Location $sys
try {
 foreach($value in @(111,222)){
   [IO.File]::WriteAllBytes($userini,$original)
   $override=[Environment]::NewLine+'[G3Config227.G3Config227Actor]'+[Environment]::NewLine+'ConfigValue='+$value+[Environment]::NewLine
   [IO.File]::AppendAllText($userini,$override,[Text.Encoding]::Default)
   $log=Join-Path $root ('G3-B08-C227k-original-native-CONFIG-UserIni-'+$value+'.log')
   & '.\G3-B08-C227k-original-native-config-transient-reload.exe' *> $log
   Write-Output ('G3_B08_CONFIG_VARIANT '+$value+' ORIGINAL_NATIVE_EXIT='+$LASTEXITCODE)
   Select-String -Path $log -Pattern 'G3_B08_NATIVE_RELOADED','G3_B08_227_INDEPENDENT_RELOAD' | Select-Object -First 7
 }
}finally{
 [IO.File]::WriteAllBytes($userini,$original)
 Write-Output ('ORIGINAL_NATIVE_UserINI_RESTORED='+(([IO.File]::ReadAllBytes($userini).Length -eq $original.Length)))
}
