# UE4.27 P07 genuine redirector payload and version-520 explicit PackageName replay.
# Audit-only. All writes are confined to C:/Temp; native oracle must already be built.
$ErrorActionPreference='Stop'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php=(Get-Command php.exe -ErrorAction Stop).Source
$editor='H:\Epic Games\UE_4.27\Engine\Binaries\Win64\UE4Editor-Cmd.exe'
$project='C:\Temp\UE427Oracle\UE427Oracle.uproject'
$oracle='C:\Temp\UE427Oracle\Plugins\UE427Oracle\Binaries\Win64\UE4Editor-UE427Oracle.dll'
foreach($needed in @($editor,$project,$oracle)){
 if(!(Test-Path -LiteralPath $needed)){throw "Missing native prerequisite: $needed"}
}
$generator=Join-Path $repo 'catalog\bin\generate-ue427-p07-explicit-package-fixture.php'
& $php $generator > 'C:\Temp\ue427-p07-explicit-generator.json'
if($LASTEXITCODE -ne 0){throw 'Explicit PackageName generator failed'}
$manifest=Get-Content 'C:\Temp\ue427-p07-explicit-generator.json' -Raw | ConvertFrom-Json
if(!$manifest.ok){throw 'Failed to validate controlled PackageName fixture'}
function Run-Native([string]$asset,[string]$log){
 & $editor $project '-run=UE427Oracle' ('-P07Runtime='+$asset) '-unattended' '-nullrhi' '-nosplash' '-nop4' '-NoSound' *> $log
 if($LASTEXITCODE -ne 0){throw "Compiled UE4.27 failure: $asset; log=$log; exit=$LASTEXITCODE"}
}
$redirectors=[ordered]@{
 provider1='/Engine/Functions/Engine_MaterialFunctions02/MAXScripts/MultiNormalScript_VertexColors'
 consumer1='/Engine/Functions/Engine_MaterialFunctions02/MAXScripts/SecondVertNormalSet_Script'
 provider2='/Engine/Functions/Engine_MaterialFunctions02/PivotPainter2/ms_PivotPainter2_CalculateCurrentIndex'
 consumer2='/Engine/Functions/Engine_MaterialFunctions02/PivotPainter2/ms_PivotPainter2_CalcuateCurrentIndex'
}
foreach($item in $redirectors.GetEnumerator()){
 Run-Native $item.Value ('C:\Temp\ue427-p07-redirector-'+$item.Key+'.log')
}
foreach($case in @('Original','ExplicitEngine','ExplicitCoreUObject')){
 Run-Native ('C:/Temp/UE427Oracle/Saved/UE427P07Probe/P07'+$case+'.uasset') ('C:\Temp\ue427-p07-pkgname-'+$case+'.log')
}
& $php (Join-Path $repo 'catalog\bin\verify-ue427-p07-native-redirectors.php')
if($LASTEXITCODE -ne 0){throw 'Native redirector payload comparison failed'}
& $php (Join-Path $repo 'catalog\bin\verify-ue427-p07-native-explicit-package.php')
if($LASTEXITCODE -ne 0){throw 'Native PackageName comparison failed'}
