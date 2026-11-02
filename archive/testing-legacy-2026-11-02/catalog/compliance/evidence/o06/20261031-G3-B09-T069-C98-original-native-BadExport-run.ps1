$ErrorActionPreference='Stop'
$dir='C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System'
$u=Join-Path $dir 'G3C98Defaults.u'
$original=[IO.File]::ReadAllBytes($u)
if($original.Length -ne 761 -or $original[275] -ne 0x82 -or $original[373] -ne 0){throw 'Original C98 immutable source fixture differs from 761-byte 1998 UCC'}
$ev='C:\Users\arden\source\repos\ut_reader\catalog\compliance\evidence\o06'
try {
 $mutated=[byte[]]$original.Clone();$mutated[275]=0x3f
 [IO.File]::WriteAllBytes($u,$mutated)
 Copy-Item $u (Join-Path $ev '20261031-G3-B08-T069-C98-original-super-invalid-export63.u') -Force
 $proc=Start-Process -FilePath (Join-Path $dir 'Unreal.exe') -ArgumentList '-make' -WorkingDirectory $dir -WindowStyle Hidden -PassThru
 $complete=$proc.WaitForExit(12000)
 if(!$complete){$proc.Kill();$proc.WaitForExit()}
 Copy-Item (Join-Path $dir 'Editor.log') (Join-Path $ev '20261031-G3-B08-T069-C98-original-compiled-BadExport63-Editor.log') -Force
 Write-Output ('ORIGINAL_1998_X86_NATIVE_INVALID_EXPORT_DONE='+$complete+' exit='+$proc.ExitCode)
 Select-String -Path (Join-Path $dir 'Editor.log') -Pattern 'Bad export index|Bad import index|IndexToObject|ULinkerLoad|Shutting down after catching|Exiting due' | Select-Object -First 11 | ForEach-Object {$_.Line}
} finally {
 [IO.File]::WriteAllBytes($u,$original)
 if((Get-FileHash $u).Hash.ToLowerInvariant() -ne '766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf'){throw 'Original 1998 source class package SHA not restored'}
 Write-Output 'ORIGINAL_1998_C98_UNCHANGED_UCC_FIXTURE_RESTORED'
}
