@echo off
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x86 >nul
if errorlevel 1 exit /b 2
cd /d C:\Temp\UnrealDB\O06
cl.exe /nologo /W4 /EHsc /std:c++17 /Zi /Fe:g3-08-original-227k-FAny-native-type-oracle.exe g3-08-original-227k-FAny-native-type-oracle.cpp /link /MACHINE:X86 /DEBUG
if errorlevel 1 exit /b 3
echo COMPILER_ORACLE_BUILD_PASS
g3-08-original-227k-FAny-native-type-oracle.exe
