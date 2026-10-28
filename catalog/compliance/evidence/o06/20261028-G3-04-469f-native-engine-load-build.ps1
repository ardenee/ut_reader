$ErrorActionPreference='Continue'
$root='C:\Temp\UnrealDB\O06'
$inc='L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Inc'
$lib='L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Lib\x86'
$vs='C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat'
$src=Join-Path $root 'g3-04-469f-native-engine-load.cpp'
$out=Join-Path $root 'Native-469f\System\G304469fEngineProbe.exe'
$cmd='call "'+$vs+'" x86 >nul && cl /nologo /EHsc /MD /Zc:wchar_t- /DWIN32=1 /D_WINDOWS=1 /DUNICODE /D_UNICODE /I "'+$inc+'" "'+$src+'" /link /LIBPATH:"'+$lib+'" Core.lib /OUT:"'+$out+'"'
cmd /d /s /c $cmd 2>&1 | Tee-Object (Join-Path $root 'g3-04-469f-native-engine-load-build.log') | Select-Object -Last 50
Write-Output "G304_469F_ENGINE_NATIVE_BUILD_EXIT=$LASTEXITCODE"
