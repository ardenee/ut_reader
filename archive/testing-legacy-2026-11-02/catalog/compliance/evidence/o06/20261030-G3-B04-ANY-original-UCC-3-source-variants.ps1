$ErrorActionPreference='Stop'
$root='C:\Temp\UnrealDB\O06\Native-227k'
$sys=Join-Path $root 'System'
$ini=Join-Path $sys 'Unreal.ini'
$uc=Join-Path $root 'G3Any227K\Classes\G3Any227KActor.uc'
$package=Join-Path $sys 'G3Any227K.u'
$originalIni=[IO.File]::ReadAllBytes($ini)
$originalUc=[IO.File]::ReadAllBytes($uc)
$originalPackage=[IO.File]::ReadAllBytes($package)
$canonical='L:\Source\Games\Unreal\OldUnreal-UnrealPatch227k-Windows\System\Unreal.ini'
try{
 $b=[IO.File]::ReadAllText($canonical,[Text.Encoding]::Default)
 $st=$b.IndexOf('[Editor.EditorEngine]');$en=$b.IndexOf('[Engine.GameInfo]',$st)
 $sec=[regex]::Replace($b.Substring($st,$en-$st),'(?m)^EditPackages=.*\r?\n','')
 $sec+='EditPackages=Core'+[Environment]::NewLine+'EditPackages=Engine'+[Environment]::NewLine+'EditPackages=Editor'+[Environment]::NewLine+'EditPackages=G3Any227K'+[Environment]::NewLine
 $b=$b.Substring(0,$st)+$sec+$b.Substring($en)
 [IO.File]::WriteAllText($ini,$b,[Text.Encoding]::Default)
 Set-Location $sys
 $variants=@(
   @('NonzeroNAME',"NameValue='G3NameAlpha'"),
   @('NonzeroOBJECT',"ObjectValue=class'Engine.Actor'"),
   @('NonzeroSTRUCT','StructValue=(X=3.25,Y=-4.5,Z=5.75)')
 )
 foreach($v in $variants){
  $label=$v[0];$assignment=$v[1]
  $body='class G3Any227KActor extends Actor;'+[Environment]::NewLine+
    'var any NameValue;'+[Environment]::NewLine+
    'var any ObjectValue;'+[Environment]::NewLine+
    'var any StructValue;'+[Environment]::NewLine+
    'defaultproperties'+[Environment]::NewLine+'{'+[Environment]::NewLine+
    ' '+$assignment+[Environment]::NewLine+'}'+[Environment]::NewLine
  [IO.File]::WriteAllText($uc,$body,[Text.Encoding]::ASCII)
  Remove-Item -LiteralPath $package -Force -ErrorAction SilentlyContinue
  $log='C:\Temp\UnrealDB\O06\G3-B04-original-2026-227k-UCC-Any-'+$label+'.log'
  & .\UCC.exe make *> $log
  $matched=(Test-Path $package)
  Write-Output ('NEW_ORIGINAL_2026_NATIVE_UCC_ANY_'+$label+' result='+$LASTEXITCODE+' produced_package='+$matched)
  Get-Content $log -Tail 12
  if($matched){
   $result='C:\Temp\UnrealDB\O06\G3Any227K-original-2026-'+$label+'.u'
   Copy-Item -LiteralPath $package -Destination $result -Force
   Write-Output ('ORIGINAL_NATIVE_COMPILED_ANY_'+$label+' bytes='+((Get-Item $result).Length)+' sha256='+(Get-FileHash $result).Hash.ToLowerInvariant())
  }
 }
}finally{
 [IO.File]::WriteAllBytes($ini,$originalIni)
 [IO.File]::WriteAllBytes($uc,$originalUc)
 [IO.File]::WriteAllBytes($package,$originalPackage)
 Write-Output 'ORIGINAL_2026_ISOLATED_INI_UC_U_RESTORED_SHA_IDENTICAL'
}
