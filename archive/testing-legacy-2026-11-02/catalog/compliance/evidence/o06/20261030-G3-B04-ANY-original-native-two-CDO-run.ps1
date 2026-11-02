$ErrorActionPreference='Stop'
$root='C:\Temp\UnrealDB\O06'
$sys=Join-Path $root 'Native-227k\System'
$dst=Join-Path $sys 'G3Any227K.u'
$old=[IO.File]::ReadAllBytes($dst)
try{
 Set-Location $sys
 foreach($label in @('NonzeroNAME','NonzeroOBJECT')){
  $src=Join-Path $root ('G3Any227K-original-2026-'+$label+'.u')
  Copy-Item -LiteralPath $src -Destination $dst -Force
  if((Get-FileHash $src).Hash -ne (Get-FileHash $dst).Hash){throw 'Original C227k UCC compiled package mismatch'}
  $log=Join-Path $root ('G3-B04-original-C227k-UAny-'+$label+'-NATIVE-CDO-LOAD.log')
  & .\G3-B04-original-C227k-UAny-name-object-Cdo-native.exe *> $log
  Write-Output ('ORIGINAL_2026_227K_NATIVE_ANY_'+$label+' exit='+$LASTEXITCODE)
  Select-String -Path $log -Pattern 'ORIGINAL_C227K_ANY_NATIVE_|Bad name index|Error|History:' | Select-Object -First 24
 }
}finally{
 [IO.File]::WriteAllBytes($dst,$old)
 Write-Output 'BASELINE_ORIGINAL_UCC_ANY_PACKAGE_RESTORED'
}
