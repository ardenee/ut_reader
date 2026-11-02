@echo off

call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x86 >nul

if errorlevel 1 exit /b 1

cl /nologo /EHsc /MD /Zc:wchar_t- /DWIN32=1 /D_WINDOWS=1 /DUNICODE /D_UNICODE /I "L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Inc" "C:\Temp\UnrealDB\O06\g3-t073-f469-original-only-Preloaded-new-struct-native-values.cpp" /link /LIBPATH:"L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Core\Lib\x86" Core.lib /OUT:"C:\Temp\UnrealDB\O06\Native-469f\System\G3-T073-f469-new-only-Preloaded-struct-values.exe"

