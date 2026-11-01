@echo off

call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x86 >nul

if errorlevel 1 exit /b 1

cl /nologo /EHsc /MD /Zc:wchar_t- /DWIN32=1 /D_WINDOWS=1 /DUNICODE /D_UNICODE /I "L:\Source\Games\UT99\Unreal Tournament v432\Core\Inc" "C:\Temp\UnrealDB\O06\g3-t073-v432-load-all-real-new-original-Class-exports.cpp" /link /LIBPATH:"L:\Source\Games\UT99\Unreal Tournament v432\Core\Lib" Core.lib /OUT:"C:\Temp\UnrealDB\O06\Native-UCC\System\G3-T073-v432-real-class-export-materialization.exe"

