@echo off
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x86 >nul
if errorlevel 1 exit /b 1
cl /nologo /EHsc /MD /DWIN32 /D_WINDOWS "C:\Temp\UnrealDB\O06\G3-T072-C98-1998-original-native-two-package-Core-then-Engine.cpp" /link /OUT:"C:\Temp\UnrealDB\O06\Native-C98-1998-G304\System\G3-T072-C98-original-1998-Core-then-Engine.exe"
