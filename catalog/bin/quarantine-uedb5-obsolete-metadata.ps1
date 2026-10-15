param(
    [Parameter(Mandatory=$true)][string]$StorageRoot,
    [Parameter(Mandatory=$true)][string]$QuarantineRoot,
    [switch]$Apply,
    [int]$MinimumAgeDays=7
)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath($StorageRoot).TrimEnd('\','/')
$meta=Join-Path $root 'metadata'
if(!(Test-Path -LiteralPath $meta -PathType Container)){throw 'Catalog metadata directory missing'}
$quarantine=[IO.Path]::GetFullPath($QuarantineRoot).TrimEnd('\','/')
if($quarantine.StartsWith($root,[StringComparison]::OrdinalIgnoreCase)){throw 'Quarantine must be outside production storage'}
$cutoff=[DateTime]::UtcNow.AddDays(-[Math]::Max(7,$MinimumAgeDays))
# Never match the canonical <id>.uedb5 files.
$pattern='^\d+\.(?:uedb[234](?:\.tmp\.[0-9a-f]{16}(?:\.payload\.[0-9a-f]{16})?|\.bak\.[0-9a-f]{16})?|uedb5\.tmp\.[0-9a-f]{16}\.payload\.[0-9a-f]{16})$'
$candidates=New-Object System.Collections.Generic.List[object]
foreach($file in [IO.Directory]::EnumerateFiles($meta,'*',[IO.SearchOption]::AllDirectories)){
 $name=[IO.Path]::GetFileName($file)
 if($name -notmatch $pattern){continue}
 $item=[IO.FileInfo]::new($file)
 if($item.LastWriteTimeUtc -ge $cutoff){continue}
 $relative=$file.Substring($meta.TrimEnd('\').Length).TrimStart('\','/')
 $candidates.Add([pscustomobject]@{relative_path=$relative;size=$item.Length;last_write_utc=$item.LastWriteTimeUtc.ToString('o');source=$file})
}
$total=($candidates|Measure-Object -Property size -Sum).Sum
if(!$Apply){
 [pscustomobject]@{ok=$true;dry_run=$true;file_count=$candidates.Count;bytes=$total;quarantine=$quarantine}|ConvertTo-Json
 exit 0
}
[IO.Directory]::CreateDirectory($quarantine)|Out-Null
$journal=Join-Path $quarantine 'move-manifest.jsonl'
$moved=0L;$bytesMoved=0L
foreach($f in $candidates){
 $target=Join-Path (Join-Path $quarantine 'metadata') $f.relative_path
 [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target))|Out-Null
 $sourceHash=(Get-FileHash -LiteralPath $f.source -Algorithm SHA256).Hash
 [IO.File]::Copy($f.source,$target,$true)
 if((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -ne $sourceHash){throw ('Quarantine copy hash mismatch: '+$f.relative_path)}
 $record=[pscustomobject]@{relative_path=$f.relative_path;bytes=$f.size;sha256=$sourceHash;last_write_utc=$f.last_write_utc;time_moved_utc=[DateTime]::UtcNow.ToString('o')}
 [IO.File]::AppendAllText($journal,($record|ConvertTo-Json -Compress)+"`n",[Text.UTF8Encoding]::new($false))
 [IO.File]::Delete($f.source)
 $moved++;$bytesMoved+=$f.size
}
[pscustomobject]@{ok=$true;dry_run=$false;files_moved=$moved;bytes_moved=$bytesMoved;quarantine=$quarantine;manifest=$journal}|ConvertTo-Json
