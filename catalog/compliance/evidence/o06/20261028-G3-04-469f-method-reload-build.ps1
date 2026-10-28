$ErrorActionPreference='Stop'
$vs='C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat'
$inc='L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Inc'
$lib='L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Lib\x86'
$src='C:\Temp\UnrealDB\O06\g3-04-469f-native-function-state-load.cpp'
$out='C:\Temp\UnrealDB\O06\Native-469f\System\G304469fMethodReload.exe'
$cmd='call "'+$vs+'" x86 >nul && cl /nologo /EHsc /MD /Zc:wchar_t- /DWIN32=1 /D_WINDOWS=1 /DUNICODE /D_UNICODE /I "'+$inc+'" "'+$src+'" /link /LIBPATH:"'+$lib+'" Core.lib /OUT:"'+$out+'"'
cmd /d /s /c $cmd
if($LASTEXITCODE -ne 0){throw 'Original 469f disposable native method reload build failed'}