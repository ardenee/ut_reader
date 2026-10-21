param()
$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$ref = Join-Path $repo 'specs\epic-source\ue4-4.27.2\package-loading'
$python = Join-Path $PSScriptRoot 'build-epic-ue427-package-reference.py'
$calibre = 'C:\Program Files\Calibre2\ebook-convert.exe'
$extract = 'C:\Program Files\Git\ucrt64\bin\pdftotext.exe'
$pdfinfo = 'C:\Program Files\Calibre2\app\bin\pdfinfo.exe'
foreach ($tool in @($calibre,$extract,$pdfinfo)) {
    if (-not (Test-Path $tool)) { throw "Required local tool missing: $tool" }
}
& py -3 $python
if ($LASTEXITCODE -ne 0) { throw 'Pinned Epic C++ source check failed' }
& py -3 (Join-Path $PSScriptRoot 'build-epic-ue427-export-filter-flow.py')
if ($LASTEXITCODE -ne 0) { throw 'Pinned Epic export-filter decision graph check failed' }
& py -3 (Join-Path $PSScriptRoot 'build-epic-source-branch-diagram.py')
if ($LASTEXITCODE -ne 0) { throw 'Epic decision-graph source check failed' }
& py -3 (Join-Path $PSScriptRoot 'build-epic-ue427-comparison-checklist.py')
if ($LASTEXITCODE -ne 0) { throw 'Separate UnrealDB mapping generation failed' }
$pdf = Join-Path $ref 'UE4.27.2-Epic-Source-Package-Format-and-Loader.pdf'
& $calibre (Join-Path $ref 'source-reference.html') $pdf --paper-size a4
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $pdf)) { throw 'Epic source-only PDF build failed' }
$text = (& $extract -layout $pdf - | Out-String)
foreach ($expected in @('Epic source-only', 'LinkerLoad.cpp:715', 'PackageFileSummary.cpp:68', 'ObjectResource.cpp:287', 'ObjectResource.cpp:110', 'SavePackage.cpp:3720')) {
    if (-not $text.Contains($expected)) { throw "Missing Epic source anchor in generated PDF: $expected" }
}
[xml]$diagram = Get-Content (Join-Path $ref 'source-reference.drawio') -Raw
$manifest = Get-Content (Join-Path $ref 'source-manifest.json') -Raw | ConvertFrom-Json
if (@($diagram.mxfile.diagram).Count -ne @($manifest.groups).Count) { throw 'PDF companion draw.io tab count differs from Epic path manifest' }
Write-Output "PASS: Epic source SHA checks, PDF anchors, $(@($diagram.mxfile.diagram).Count) editable diagram pages"
& $pdfinfo $pdf | Select-String 'Pages:|Page size:|File size:'
