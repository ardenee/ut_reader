param()
$ErrorActionPreference = 'Stop'
$repo = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
$trace = Join-Path $repo 'specs\epic-source\ue4-4.27.2\verifyimport'
$builder = Join-Path $PSScriptRoot 'build-epic-source-trace.py'
$convert = 'C:\Program Files\Calibre2\ebook-convert.exe'
$pdfText = 'C:\Program Files\Git\ucrt64\bin\pdftotext.exe'
if (-not (Test-Path $convert)) { throw "Calibre PDF converter missing: $convert" }
if (-not (Test-Path $pdfText)) { throw "PDF inspection utility missing: $pdfText" }
& py -3 $builder
if ($LASTEXITCODE -ne 0) { throw "Epic pinned-source trace generator failed" }
$html = Join-Path $trace 'source-path.html'
$pdf = Join-Path $trace 'UE4.27.2-VerifyImport-Epic-Source-Trace.pdf'
& $convert $html $pdf --paper-size a4
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $pdf)) { throw "PDF conversion failed" }
$text = (& $pdfText -layout $pdf - | Out-String)
foreach ($required in @('Epic source-only', 'LinkerLoad.cpp:2792', 'LinkerLoad.cpp:3068', 'LinkerLoad.cpp:3082')) {
    if (-not $text.Contains($required)) { throw "PDF missing Epic anchor: $required" }
}
Write-Output "PASS: Epic source anchors; source PDF content; $pdf"
