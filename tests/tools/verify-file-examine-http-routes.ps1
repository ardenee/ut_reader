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
     if($html -notmatch '<th[^>]*>Root \(derived\)</th>\s*<th[^>]*>Object name</th>\s*<th[^>]*>Full path \(derived\)</th>'){
       throw 'UE2 Imports Root/Object/Path columns not adjacent or reordered'
     }
     if($html.Contains('Original indexed source record (serialized fields and record provenance)')){
       throw 'Obsolete multi-line grouped header still present'
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
 $fatal=Select-String -Path (Join-Path $work 'php-stderr.log') -Pattern 'PHP Fatal error|Uncaught Error|Class "PdoPackageTablePageQuery" not found' -ErrorAction SilentlyContinue
 if($fatal){throw ('PHP_SERVER_FATAL '+($fatal|Select-Object -First 1).Line)}
 [pscustomobject]@{
   pass=$true;routes_checked=$results.Count;failed_routes=0;
   original_file_sample_count=$cases.Count;entries=@($results)
 } | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $work 'results.json') -Encoding UTF8
 Write-Output "ALL_HTTP_ROUTES_PASS=$($results.Count)"
} finally {
 if($proc){Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue}
}
