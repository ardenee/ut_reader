$ErrorActionPreference='Stop'
$dir='C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System'
$root='C:\Temp\UnrealDB\O06'
$u=Join-Path $dir 'G3C98Defaults.u'
$original=[IO.File]::ReadAllBytes($u)
if($original.Length -ne 761 -or $original[275] -ne 0x82 -or $original[373] -ne 0){throw 'Original 1998 C98 actual compiled class package did not match exact source'}
$prevLog=Join-Path $dir 'Editor.log'
$sealed='C:\Users\arden\source\repos\ut_reader\catalog\compliance\evidence\o06'
Copy-Item $prevLog (Join-Path $sealed '20261031-G3-B08-T069-C98-original-compiled-BadName63-physical374-Editor.log') -Force
Copy-Item $u (Join-Path $sealed '20261031-G3-B08-T069-C98-original-compiled-G3C98Defaults-761.u') -Force
try{
 $mutated=[byte[]]$original.Clone()
 $mutated[275]=0xBF
 [IO.File]::WriteAllBytes($u,$mutated)
 Copy-Item $u (Join-Path $sealed '20261031-G3-B08-T069-C98-original-super-invalid-import63.u') -Force
 Set-Location $dir
 $p=Start-Process -FilePath (Join-Path $dir 'Unreal.exe') -ArgumentList '-make' -PassThru -WindowStyle Hidden -WorkingDirectory $dir
 $completed=$p.WaitForExit(12000)
 if(!$completed){$p.Kill();$p.WaitForExit();Write-Output 'ORIGINAL_C98_UNREAL_EXE_TIMED_OUT_TERMINATED_TEMP_FIXTURE_ONLY'}
 Write-Output ('ORIGINAL_COMPILED_1998_UNREAL_CORE_NATIVE_EXIT='+$p.ExitCode+' timedOut='+(!$completed))
 Copy-Item (Join-Path $dir 'Editor.log') (Join-Path $sealed '20261031-G3-B08-T069-C98-original-compiled-BadImport63-Editor.log') -Force
 $log=Get-Content (Join-Path $dir 'Editor.log') -Raw
 foreach($term in @('Bad import index','Bad name index','Class G3C98Defaults','LinkerLoad','Exit: Exiting')){
  Write-Output ('ORIGINAL_C98_NATIVE_LOG_'+$term+'='+($log.Contains($term)))
 }
 Select-String -Path (Join-Path $dir 'Editor.log') -Pattern 'Bad import|Critical:|LinkerLoad|Exiting due|Error|Error called' | Select-Object -Last 20
}finally{
 [IO.File]::WriteAllBytes($u,$original)
 if(-not [IO.File]::ReadAllBytes($u).SequenceEqual($original)){throw 'Original 1998 UCC real class .u package restoration FAILED'}
 Write-Output ('ORIGINAL_1998_UCC_REAL_CLASS_PACKAGE_RESTORED_SHA256='+(Get-FileHash $u).Hash.ToLowerInvariant())
}
