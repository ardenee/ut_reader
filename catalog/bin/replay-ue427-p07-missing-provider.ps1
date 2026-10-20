# Bounded P07 UE4.27.2 real-runtime missing script provider replay.
# Run on DEV (game-amd); canonical Epic source and engine binaries remain unchanged.
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$php = (Get-Command php.exe -ErrorAction Stop).Source
$editor = 'H:\Epic Games\UE_4.27\Engine\Binaries\Win64\UE4Editor-Cmd.exe'
$project = 'C:\Temp\UE427Oracle\UE427Oracle.uproject'
$oracle = 'C:\Temp\UE427Oracle\Plugins\UE427Oracle\Binaries\Win64\UE4Editor-UE427Oracle.dll'
$source = 'H:\Epic Games\UE_4.27\Engine\Content\Animation\DefaultAnimBoneCompressionSettings.uasset'
$folder = 'C:\Temp\UE427Oracle\Saved\UE427P07Probe'
$baseline = Join-Path $folder 'DefaultAnimBoneCompressionSettings.uasset'
$mutant = Join-Path $folder 'MissingNativeScript.uasset'
foreach($needed in @($editor, $project, $oracle, $source)) {
    if(-not (Test-Path -LiteralPath $needed)) {
        throw "Missing P07 replay prerequisite: $needed"
    }
}
New-Item -ItemType Directory -Force -Path $folder | Out-Null
Copy-Item -LiteralPath $source -Destination $baseline -Force
$generator = Join-Path $repo 'catalog\bin\generate-ue427-p07-missing-script-fixture.php'
$checker = Join-Path $repo 'catalog\bin\verify-ue427-p07-native-missing-script.php'
$generationLog = 'C:\Temp\ue427-p07-missing-script-generator.json'
& $php $generator > $generationLog
if($LASTEXITCODE -ne 0){throw 'Isolated fixture generator failed'}
$generated = Get-Content $generationLog -Raw | ConvertFrom-Json
if(-not $generated.ok -or -not (Test-Path $mutant)){
    throw 'Missing or invalid isolated P07 fixture'
}
function Run-NativeFixture([string]$fixturePath,[string]$logPath) {
    $argument = '-P07Runtime=' + $fixturePath.Replace('\','/')
    & $editor $project '-run=UE427Oracle' $argument '-unattended' '-nullrhi' '-nosplash' '-nop4' '-NoSound' *> $logPath
    if($LASTEXITCODE -ne 0){throw "Compiled UE4.27.2 runtime exited with code $LASTEXITCODE; log=$logPath"}
}
Run-NativeFixture $baseline 'C:\Temp\ue427-p07-path-probe.log'
Run-NativeFixture $mutant 'C:\Temp\ue427-p07-missing-native-runtime.log'
& $php $checker
if($LASTEXITCODE -ne 0){throw 'Native/PHP missing-provider comparison failed'}
