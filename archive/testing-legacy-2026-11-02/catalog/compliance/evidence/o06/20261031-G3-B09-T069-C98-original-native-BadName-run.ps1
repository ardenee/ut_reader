$ErrorActionPreference='Stop'
$dir='C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System'
$file=Join-Path $dir 'G3C98Defaults.u'
$original=[IO.File]::ReadAllBytes($file)
if($original.Length -ne 761 -or $original[373] -ne 0){throw ('C98 source original expected 761 and None0 at373, got '+$original.Length+' '+$original[373])}
$cdb='C:\Program Files (x86)\Windows Kits\10\Debuggers\x86\cdb.exe'
Set-Location $dir
try {
 $bytes=[byte[]]$original.Clone();$bytes[373]=0x3f
 [IO.File]::WriteAllBytes($file,$bytes)
 Write-Output ('ORIGINAL_C98_SINGLE_BYTE_MUTATION offset373 old0 new63 len='+$bytes.Length)
 $out='C:\Temp\UnrealDB\O06\G3-B08-T064-original-C98-MissingTerm.log'
 & $cdb -cf 'C:\Temp\UnrealDB\O06\G3-T058-C98-actual-filepos.cmd' -logo $out -o -G '.\Unreal.exe' '-make' *> 'C:\Temp\UnrealDB\O06\G3-B08-T064-original-C98-MissingTerm-stdout.log'
 Write-Output ('ORIGINAL_C98_DEBUG_EXIT='+$LASTEXITCODE)
 Select-String -Path $out -Pattern 'Bad name|Fatal|error|name index|FARCHIVE|Exception|first chance|G3_T058|invalid' | Select-Object -Last 14
}finally {
 [IO.File]::WriteAllBytes($file,$original)
 if((Get-FileHash $file).Hash -ne ([System.BitConverter]::ToString([Security.Cryptography.SHA256]::HashData($original)).Replace('-',''))){throw 'C98 original .u restore hash mismatch'}
 Write-Output 'ORIGINAL_C98_COMPILER_FIXTURE_FILE_RESTORED_SHA_MATCH'
}
