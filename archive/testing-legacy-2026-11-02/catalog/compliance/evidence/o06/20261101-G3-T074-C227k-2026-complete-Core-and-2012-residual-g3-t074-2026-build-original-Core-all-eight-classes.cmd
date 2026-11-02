@echo off
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x86 >nul
if errorlevel 1 exit /b 1
cl /nologo /EHsc /MD /DWIN32=1 /D_WINDOWS=1 /DUNICODE /D_UNICODE /I "L:\Source\Games\Unreal\OldUnreal-UnrealPatch227k-SDK-Windows\Core\Inc" "C:\Temp\UnrealDB\O06\g3-t074-2026-original-Core-eight-classes-full-direct-native.cpp" /link /LIBPATH:"L:\Source\Games\Unreal\OldUnreal-UnrealPatch227k-SDK-Windows\Core\Lib\x86" Core.lib /OUT:"C:\Temp\UnrealDB\O06\Native-227k\System\G3-T074-227k-original-Core-target-8-classes.exe"
