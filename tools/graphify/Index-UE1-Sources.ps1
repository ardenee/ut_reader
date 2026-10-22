$ErrorActionPreference='Stop'
$g='C:\Users\arden\Graphify'
$root='L:\Source\Games\Unreal'
$ut='L:\Source\Games\UT99'
$all=@(
 @{id='ue1-v082';name='Unreal v0.82';version='v0.82 (1996)';source=(Join-Path $root 'Unreal [v0.82] [1996-00-00]');detail='1996 UE1 engine and game source'},
 @{id='ue1-v083';name='Unreal v0.83';version='v0.83 (1996-10-01)';source=(Join-Path $root 'Unreal [v0.83] [1996-10-01]');detail='Early UE1 resource format'},
 @{id='ue1-v084a';name='Unreal v0.84a';version='v0.84a (1996-12-11)';source=(Join-Path $root 'Unreal [v0.84a] [1996-12-11]');detail='Original v9/v10 registered classes'},
 @{id='ue1-v086x';name='Unreal v0.86x';version='v0.86x (1997-05-25)';source=(Join-Path $root 'Unreal [v0.86x] [1997-05-25]');detail='Intermediate Unrealfile version'},
 @{id='ue1-v1200';name='Unreal v1.200';version='v1.200 (1998-05-19)';source=(Join-Path $root 'Unreal [v1.200] [1998-05-19]');detail='Early classic UE1 archive version'},
 @{id='ue1-v1224';name='Unreal v1.224 (INCOMPLETE)';version='v1.224 (1999-05-01)';source=(Join-Path $root 'Unreal [v1.224] [1999-05-01] [INCOMPLETE]');detail='INCOMPLETE source tree; partial structural coverage'},
 @{id='ue1-v1227';name='Unreal v1.227';version='v1.227';source=(Join-Path $root 'Unreal [v1.227]');detail='Later classic Unreal source'},
 @{id='ue1-ut400';name='Unreal Tournament v400';version='UT v400 (1999-11-30)';source=(Join-Path $ut 'Unreal Tournament [v1.400] [1999-11-30]');detail='UT99 v400 engine, game and tools'},
 @{id='ue1-ut432';name='Unreal Tournament v432';version='UT v432';source=(Join-Path $ut 'Unreal Tournament v432');detail='UT99 later version engine/game source'}
)
$journal=Join-Path $g 'UE1-Index-Journal.jsonl'
foreach($x in $all){
 $name=$x.id;$target=Join-Path $g $name
 if(!(Test-Path -LiteralPath $x.source)){Write-Output ("MISSING_SOURCE $name");continue}
 $meta=Join-Path $target 'project.json'
 New-Item -ItemType Directory -Force -Path $target | Out-Null
 $info=[ordered]@{name=$x.name;family='UE1';version=$x.version;source=$x.source;detail=$x.detail}
 [System.IO.File]::WriteAllText($meta,($info|ConvertTo-Json -Depth 5),[Text.Encoding]::UTF8)
 if(Test-Path (Join-Path $target 'graphify-out\graph.json')){Write-Output ("UNCHANGED_GRAPH $name");continue}
 $start=[ordered]@{at_utc=[DateTime]::UtcNow.ToString('o');id=$name;state='STARTED';source=$x.source}
 [IO.File]::AppendAllText($journal,(($start|ConvertTo-Json -Compress)+[Environment]::NewLine),[Text.Encoding]::UTF8)
 Write-Output ("INDEX_BEGIN "+$name)
 try{
   & (Join-Path $g 'Index-Code.ps1') -Name $name -Source $x.source -Workers 4
   if($LASTEXITCODE -ne 0){throw "Unexpected subprocess exit: $LASTEXITCODE"}
   $graph=Join-Path $target 'graphify-out\graph.json'
   if(!(Test-Path $graph)){throw "Graph missing after extraction"}
   $end=[ordered]@{at_utc=[DateTime]::UtcNow.ToString('o');id=$name;state='PASS';graph_bytes=(Get-Item $graph).Length}
 }catch{
   $end=[ordered]@{at_utc=[DateTime]::UtcNow.ToString('o');id=$name;state='FAIL';error=$_.ToString()}
   Write-Output ("INDEX_FAILURE "+$name+" : "+$_.ToString())
 }
 [IO.File]::AppendAllText($journal,(($end|ConvertTo-Json -Compress)+[Environment]::NewLine),[Text.Encoding]::UTF8)
 Write-Output ("INDEX_RESULT "+$name+" "+$end.state)
}
Write-Output 'UE1_INDEX_BATCH_COMPLETE'
