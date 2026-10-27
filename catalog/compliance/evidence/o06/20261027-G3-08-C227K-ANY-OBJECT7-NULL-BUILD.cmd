@echo off
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x86 >nul
cd /d C:\Temp\UnrealDB\O06
cl.exe /nologo /W4 /EHsc /std:c++17 /Zi /Fe:g3-08-FAny-OBJECT7-EXPERIMENT.exe g3-08-FAny-OBJECT7-EXPERIMENT.cpp /link /MACHINE:X86 /DEBUG
