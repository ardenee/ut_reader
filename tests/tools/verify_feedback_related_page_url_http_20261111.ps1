$ErrorActionPreference='Stop'
$base='http://192.168.0.55/catalog/feedback.php'
$return='file-examine.php%3Fid%3D1247732%26page%3D2%26page_size%3D250%26tab%3Dexports%26target%3Dexport-301'
$cases=@(
 @{name='deep-link-prefill';url=($base+'?return_to='+$return);expect='http://192.168.0.55/catalog/file-examine.php?id=1247732&page=2&page_size=250&tab=exports&target=export-301'},
 @{name='explicit-page-url-wins';url=($base+'?return_to='+$return+'&page_url=https%3A%2F%2Funrealdb.com%2Fcatalog%2Fgames.php');expect='https://unrealdb.com/catalog/games.php'},
 @{name='main-menu-feedback-from-referrer';url=$base;expect='http://192.168.0.55/catalog/index.php'},
 @{name='unsafe-return-restricted';url=($base+'?return_to=https%3A%2F%2Fevil.invalid%2Faccount');expect='http://192.168.0.55/catalog/index.php'}
)
foreach($case in $cases) {
 $res=Invoke-WebRequest -Uri $case.url -UseBasicParsing -TimeoutSec 25 -Headers @{Referer='http://192.168.0.55/catalog/index.php'}
 $m=[regex]::Match($res.Content,'<input type="url" name="page_url"[^>]*\bvalue="([^"]*)"')
 $value=[System.Net.WebUtility]::HtmlDecode($m.Groups[1].Value)
 if($res.StatusCode -ne 200 -or !$m.Success -or $value -cne $case.expect) { throw ('FAIL '+$case.name+' HTTP='+$res.StatusCode+' value='+$value+' expected='+$case.expect)}
 Write-Output ('PASS '+$case.name+' HTTP='+$res.StatusCode+' URL='+$value)
}
$https='https://192.168.0.55/catalog/feedback.php?return_to='+$return
$body= & curl.exe -k -sS --max-time 25 -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36' -e 'https://192.168.0.55/catalog/file-examine.php?id=1247732' $https
$match=[regex]::Match(([string]::Join([Environment]::NewLine,$body)),'<input type="url" name="page_url"[^>]*\bvalue="([^"]*)"')
$val=[System.Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
$want='https://192.168.0.55/catalog/file-examine.php?id=1247732&page=2&page_size=250&tab=exports&target=export-301'
if(!$match.Success -or $val -cne $want) {throw ('FAIL HTTPS-source '+$val+' expected='+$want)}
Write-Output ('PASS HTTPS-source EXACT_URL='+$val)
