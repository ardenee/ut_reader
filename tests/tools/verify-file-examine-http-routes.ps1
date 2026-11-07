# Live HTTP route acceptance. This tests the *actual* file-examine.php
# entry point, which source-field helpers or PHP lint cannot exercise alone.
# Supply UNREALDB_CATALOG_CONFIG pointing to a scoped test
# configuration with a reachable catalogue DB and original UEDB5 storage.
# Requests use GET but may produce normal site access telemetry.
$ErrorActionPreference='Stop'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$root=if($env:UNREALDB_EXAMINE_TEST_DOCROOT){$env:UNREALDB_EXAMINE_TEST_DOCROOT}else{Join-Path $repo 'catalog'}
if(-not(Test-Path -LiteralPath $root)){throw 'Examiner document root is unavailable'}
$php=if($env:UNREALDB_TEST_PHP){$env:UNREALDB_TEST_PHP}else{'D:\php8.5\php.exe'}
if(-not(Test-Path -LiteralPath $php)){throw 'PHP executable not found'}
if(-not $env:UNREALDB_CATALOG_CONFIG -or -not(Test-Path -LiteralPath $env:UNREALDB_CATALOG_CONFIG)){
 throw 'Set UNREALDB_CATALOG_CONFIG to a dedicated test config before HTTP acceptance'
}
$port=if($env:UNREALDB_EXAMINE_TEST_PORT){[int]$env:UNREALDB_EXAMINE_TEST_PORT}else{18762}
$work=Join-Path $env:TEMP ('unrealdb-file-examine-route-acceptance-'+$port)
New-Item -ItemType Directory -Force -Path $work|Out-Null
$proc=$null
$cases=@(
 @{game='UT99';id=113253;zen=$false},
 @{game='Unreal';id=110902;zen=$false},
 @{game='Unreal2';id=1253000;zen=$false},
 @{game='UT2003';id=148919;zen=$false},
 @{game='UT2004';id=111460;zen=$false},
 @{game='UT3';id=112300;zen=$false},
 @{game='UT4';id=113526;zen=$false},
 @{game='Satisfactory';id=1390232;zen=$true}
)
$results=[System.Collections.Generic.List[object]]::new()
try{
 $proc=Start-Process -FilePath $php -ArgumentList @('-S',"127.0.0.1:$port",'-t',$root) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $work 'php-stdout.log') -RedirectStandardError (Join-Path $work 'php-stderr.log')
 if(-not $proc){throw 'Local PHP server did not start'}
 Start-Sleep -Milliseconds 750
 foreach($case in $cases){
  foreach($tab in @('names','imports','exports')){
   $pageSize=if($case.id -eq 1253000 -and $tab -eq 'imports'){250}else{20}
   $url="http://127.0.0.1:$port/file-examine.php?id=$($case.id)&tab=$tab&page_size=$pageSize"
   $output=Join-Path $work "page-$($case.id)-$tab.html"
   $code= & curl.exe --noproxy '*' -sS -A 'Mozilla/5.0' --max-time 65 -w '%{http_code}' -o $output $url
   if($LASTEXITCODE -ne 0){throw "HTTP transport failed $($case.game) $tab"}
   $html=[IO.File]::ReadAllText($output)
   $hasTable=if($case.zen){$html.Contains('examine-zen-')}else{$html.Contains('examine-source-table')}
   if($code -ne '200' -or $html.Contains('<h1>Error</h1>') -or $html.Contains('Class &quot;PdoPackageTablePageQuery&quot; not found') -or -not $hasTable){
     $match=[regex]::Match($html,'(?s)<div class="card"><h1>Error</h1>.{0,800}')
     if($match.Success){Write-Output ('HTML_ERROR '+$match.Value)}
     throw "ROUTE_FAIL game=$($case.game) tab=$tab HTTP=$code bytes=$($html.Length) has_table=$hasTable"
   }
   if($case.zen -and $tab -eq 'exports'){
     if(-not $html.Contains('RawIndex (uint32)') -or -not $html.Contains('Number (uint32)')){
       throw 'UE5 Zen ExportMap lost one FMappedName source word'
     }
   }
   if($case.id -eq 1253000 -and $tab -eq 'imports'){
     # Assert the real HTTP output has human-readable compact FNames. The
     # Name table index and the complete original FName number must remain
     # accessible, even though their labels no longer clutter each cell.
     $fname='<a class="xref mono examine-fname" title="Name index: 1297; FName number: 0" data-fname-index="1297" data-fname-number="0" href="[^"]*target=name-1297[^"]*">Core</a>'
     if($html -notmatch $fname){
       throw 'UE2 Imports FName must show linked Core with raw index/number tooltip'
     }
     if($html -notmatch '<th[^>]*>Index</th>\s*<th[^>]*>Ref</th>\s*<th[^>]*>Offset</th>\s*<th[^>]*>Class</th>\s*<th[^>]*>Type</th>'){
       throw 'UE2 Imports compact Index/Ref/Offset/Class/Type headings not present'
     }
     if($html -notmatch '<th[^>]*>Root \(derived\)</th>\s*<th[^>]*>Outer</th>\s*<th[^>]*>Object name</th>\s*<th[^>]*>Full path \(derived\)</th>'){
       throw 'UE2 Imports Root/Outer/Object/Path columns are not adjacent'
     }
     if($html -match '>32b</small>' -or $html -match '<small class="examine-outer-width'){
       throw 'OuterIndex serial width was incorrectly appended to the visible ref'
     }
     if($html -notmatch 'class="xref mono examine-source-outer-ref" href="[^"]*target=import-0[^"]*" title="Original OuterIndex: -1; serialized width: 32 bits[^"]*" data-outer-width-bits="32">-1</a>'){
       throw 'UE2 original OuterIndex must remain -1, clickable with its 32-bit width only on hover'
     }
     if($html -notmatch 'class="xref mono examine-import-self-ref" href="[^"]*target=import-1[^"]*"[^>]*>-2</a>'){
       throw 'UE2 Ref -2 must link to its original ImportMap row 1'
     }
     if($html -notmatch 'class="mono examine-source-outer-ref" title="Original OuterIndex: 0; serialized width: 32 bits[^"]*" data-outer-width-bits="32">0</span>'){
       throw 'UE2 null original OuterIndex must show the actual serialized zero'
     }
     if($html -notmatch 'Mission_09T.Surface_Floor.PlasFloorUO9E400mtv1'){
       throw 'Reconstructed UE2 Root/Outer/Object chain lost actual source-derived full path'
     }
     if($html -notmatch 'class="xref mono examine-outer-name" href="[^"]*target=import-0[^"]*"[^>]*>Engine</a>'){
       throw 'UE2 Outer reference -1 must display linked Engine target from Import row 0'
     }
     if($html -notmatch 'class="xref mono examine-outer-name" href="[^"]*target=import-4[^"]*"[^>]*>Surface_Floor</a>'){
       throw 'UE2 Outer reference -5 must display linked Surface_Floor from Import row 4'
     }
     if($html.Contains('Original indexed source record (serialized fields and record provenance)')){
       throw 'Obsolete multi-line grouped header still present'
     }
     # The route has #package-tables, NOT a row target. Before this patch,
     # building Outer links overwrote the requested $target variable and
     # accidentally highlighted import rows 41, 58 and several others.
     if([regex]::IsMatch($html,'<tr id="import-[0-9]+" class="is-reference-target"')){
       throw 'Imports were highlighted without a requested row target'
     }
     $sourceRow41=[regex]::Match($html,'(?s)<tr id="import-41"[^>]*>(.*?)</tr>').Groups[1].Value
     $sourceRow58=[regex]::Match($html,'(?s)<tr id="import-58"[^>]*>(.*?)</tr>').Groups[1].Value
     $sourceRow1=[regex]::Match($html,'(?s)<tr id="import-1"[^>]*>(.*?)</tr>').Groups[1].Value
     if((-not $sourceRow41.Contains('class="xref mono examine-matched-export"')) -or
         ($sourceRow41 -notmatch 'file-examine.php\?id=111140&amp;tab=exports[^"]*target=export-425#export-425') -or
         ($sourceRow41 -notmatch '>AtlantisT\.Floor</a>')){
       throw 'AtlantisT.Floor must link to its real Export #425 in physical file #111140'
     }
     if($sourceRow58.Contains('examine-matched-export')){
       throw '343T top-level ImportMap package record is not itself a provider export'
     }
     if(($sourceRow1 -notmatch '<details class="examine-export-options">') -or
         ($sourceRow1 -notmatch 'Engine\.StaticMeshActor') -or
         ($sourceRow1 -notmatch '\(2 exports\)') -or
         ($sourceRow1 -notmatch 'id=111198&amp;tab=exports[^"]*target=export-6548') -or
         ($sourceRow1 -notmatch 'id=149518&amp;tab=exports[^"]*target=export-10183')){
       throw 'Two Engine.StaticMeshActor source exports must be presented without guessing one provider'
     }
   }
   $results.Add([pscustomobject]@{
     game=$case.game;file_id=$case.id;tab=$tab;page_size=$pageSize;
     http_status=[int]$code;html_bytes=$html.Length;source_table_rendered=$hasTable
   })
   Write-Output "HTTP_PASS $($case.game) $($case.id) $tab size=$pageSize bytes=$($html.Length)"
   Remove-Item -LiteralPath $output -Force
  }
 }
 # An explicit selected Import row still highlights exactly that one row,
 # while an ordinary #package-tables request highlights no Import rows.
 $selectUrl="http://127.0.0.1:$port/file-examine.php?id=1253000&tab=imports&page_size=250&target=import-41"
 $selectedFile=Join-Path $work 'selected-import-41.html'
 $selectedHttp=& curl.exe --noproxy '*' -sS -A 'Mozilla/5.0' --max-time 65 -w '%{http_code}' -o $selectedFile $selectUrl
 if($LASTEXITCODE -ne 0 -or $selectedHttp -ne '200'){throw 'SELECTED_IMPORT_TARGET_ROUTE_FAILED'}
 $selectedHtml=[IO.File]::ReadAllText($selectedFile)
 $highlights=[regex]::Matches($selectedHtml,'<tr id="import-[0-9]+" class="is-reference-target"')
 if($highlights.Count -ne 1 -or $highlights[0].Value -ne '<tr id="import-41" class="is-reference-target"'){
   throw "Explicit selected ImportMap target highlighted wrong rows: $($highlights.Count)"
 }
 Write-Output 'SELECTED_IMPORT_PASS index=41 highlights=1'
 $results.Add([pscustomobject]@{
   game='Unreal2';file_id=1253000;tab='imports';target='import-41';
   http_status=[int]$selectedHttp;selected_rows=1
 })
 Remove-Item -LiteralPath $selectedFile -Force
 # Follow known links to the actual physical ExportMap row through the same
 # PHP HTTP entry point, verifying its source path and exact targeted index.
 foreach($export in @(
   @{file=111140;index=425;path='AtlantisT.Floor'},
   @{file=111198;index=6548;path='Engine.StaticMeshActor'},
   @{file=149518;index=10183;path='Engine.StaticMeshActor'}
 )){
   $url="http://127.0.0.1:$port/file-examine.php?id=$($export.file)&tab=exports&page_size=250&target=export-$($export.index)"
   $page=Join-Path $work "matched-provider-$($export.file)-$($export.index).html"
   $status=& curl.exe --noproxy '*' -sS -A 'Mozilla/5.0' --max-time 65 -w '%{http_code}' -o $page $url
   if($LASTEXITCODE -ne 0 -or $status -ne '200'){throw "TARGET_EXPORT_ROUTE_FAILED $url HTTP=$status"}
   $html=[IO.File]::ReadAllText($page)
   $entry='export-'+$export.index
   $row=[regex]::Match($html,'(?s)<tr id="'+[regex]::Escape($entry)+'"[^>]*>(.*?)</tr>').Groups[1].Value
   if(-not $row.Contains($export.path) -or -not $html.Contains('id="'+$entry+'" class="is-reference-target"')){
     throw "TARGET_EXPORT_INVALID file=$($export.file) row=$entry path=$($export.path)"
   }
   Write-Output "TARGET_EXPORT_PASS file=$($export.file) export=$($export.index)"
   $results.Add([pscustomobject]@{
     game='Unreal2';file_id=$export.file;tab='exports';target=$entry;
     http_status=[int]$status;source_export_path=$export.path
   })
   Remove-Item -LiteralPath $page -Force
 }
 $fatal=Select-String -Path (Join-Path $work 'php-stderr.log') -Pattern 'PHP Fatal error|Uncaught Error|Class "PdoPackageTablePageQuery" not found' -ErrorAction SilentlyContinue
 if($fatal){throw ('PHP_SERVER_FATAL '+($fatal|Select-Object -First 1).Line)}
 [pscustomobject]@{
   pass=$true;routes_checked=$results.Count;failed_routes=0;
   original_file_sample_count=$cases.Count;verified_export_target_count=3;entries=@($results)
 } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $work 'results.json') -Encoding UTF8
 Write-Output "ALL_HTTP_ROUTES_PASS=$($results.Count)"
} finally {
 if($proc){Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue}
}
