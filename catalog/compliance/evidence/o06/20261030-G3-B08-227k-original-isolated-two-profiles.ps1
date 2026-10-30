$ErrorActionPreference='Stop'
$root='C:\Temp\UnrealDB\O06'
$sys=Join-Path $root 'Native-227k\System'
$userIni=Join-Path $sys 'User.ini'
$langIni=Join-Path $sys 'G3Config227.int'
$origUser=[IO.File]::ReadAllBytes($userIni)
$hadLang=Test-Path -LiteralPath $langIni
if($hadLang){$origLang=[IO.File]::ReadAllBytes($langIni)}
Set-Location $sys
try{
 foreach($entry in @(@(111,'Native Alpha'),@(222,'Native Beta'))){
   $code=[int]$entry[0];$phrase=[string]$entry[1]
   [IO.File]::WriteAllBytes($userIni,$origUser)
   [IO.File]::AppendAllText($userIni,[Environment]::NewLine+'[G3Config227.G3Config227Actor]'+[Environment]::NewLine+'ConfigValue='+$code+[Environment]::NewLine,[Text.Encoding]::Default)
   $localized='[G3Config227Actor]'+[Environment]::NewLine+'LocalMessage="'+$phrase+'"'+[Environment]::NewLine
   [IO.File]::WriteAllText($langIni,$localized,[Text.Encoding]::Default)
   [IO.File]::WriteAllText((Join-Path $root ('G3-B08-C227k-ORIGINAL-LANG-'+$code+'.int')),$localized,[Text.Encoding]::Default)
   $log=Join-Path $root ('G3-B08-C227k-original-native-LOCALIZED-'+$code+'.log')
   & '.\G3-B08-C227k-original-native-config-localization-reload.exe' *> $log
   Write-Output ('G3_B08_LOCALIZED_NATIVE_PROFILE '+$code+' EXIT='+$LASTEXITCODE)
   Select-String -Path $log -Pattern 'G3_B08_NATIVE_LOCALIZED','G3_B08_NATIVE_RELOADED' | Select-Object -First 7
 }
}finally{
 [IO.File]::WriteAllBytes($userIni,$origUser)
 if($hadLang){[IO.File]::WriteAllBytes($langIni,$origLang)}else{Remove-Item -LiteralPath $langIni -Force -ErrorAction SilentlyContinue}
 Write-Output ('ORIGINAL_USER_INI_RESTORED='+([IO.File]::ReadAllBytes($userIni).Length -eq $origUser.Length))
 Write-Output ('ORIGINAL_LOCALIZATION_PROFILE_RESTORED=' + ((Test-Path -LiteralPath $langIni) -eq $hadLang))
}
