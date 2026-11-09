<?php
/**
 * No DB connection or session required: anonymous direct-entry throttle.
 * This is a friction gate rather than bot/captcha proof. Signed proof tokens
 * prevent simple forged cookies from bypassing the PHP entry-point guard.
 */
declare(strict_types=1);

final class CatalogDirectEntryGate
{
    private const ISSUE = 'UEDB_GATE_ISSUE';
    private const PASS = 'UEDB_GATE_PASS';
    private const TIME_TO_CONTINUE = 3;
    private const ISSUE_TTL = 300;
    private const PASS_TTL = 1800;
    private const ROUTES = [
        'file-examine.php' => true,
        'game-files.php' => true,
        'access-matrix.php' => true,
    ];

    public static function internalClient(string $ip): bool
    {
        if ($ip === '::1' || str_starts_with($ip, '127.')
            || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return true;
        }
        if (preg_match('/^172\.(\d{1,2})\./D', $ip, $m)) {
            return (int)$m[1] >= 16 && (int)$m[1] <= 31;
        }
        return false;
    }

    public static function trustedReferer(string $referrer): bool
    {
        if ($referrer === '') return false;
        $scheme = strtolower((string)parse_url($referrer, PHP_URL_SCHEME));
        $host = strtolower(rtrim((string)parse_url($referrer, PHP_URL_HOST), '.'));
        if (!in_array($scheme, ['http','https'], true)) return false;
        if (in_array($host, [
            'unrealdb.com','www.unrealdb.com','192.168.0.55','192.168.0.51',
            'localhost','127.0.0.1','bing.com','www.bing.com',
            'duckduckgo.com','www.duckduckgo.com','brave.com','search.brave.com',
            'ecosia.org','www.ecosia.org','search.yahoo.com'
        ], true)) return true;
        return preg_match('/^(?:www\.)?google\.(?:com|ie|co\.uk|de|fr|es|it|ca|com\.au|co\.jp|nl|be|ch|pl|pt|com\.br|co\.in)$/D', $host) === 1;
    }

    public static function eligibleRoute(string $name): bool
    {
        return isset(self::ROUTES[$name]);
    }

    public static function validatedReturn(string $value): ?string
    {
        if ($value === '' || strlen($value) > 1600
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $value)
            || str_contains($value, '#') || str_contains($value, '//')) return null;
        $path = parse_url($value);
        if (!is_array($path) || isset($path['scheme'], $path['host'])
            || isset($path['host']) || isset($path['user']) || isset($path['fragment'])) return null;
        $relative = (string)($path['path'] ?? '');
        if (!str_starts_with($relative,'/catalog/')
            || !self::eligibleRoute(substr($relative,9))) return null;
        return $value;
    }

    private static function secret(): string
    {
        $file = getenv('UNREALDB_GATE_SECRET_PATH') ?: __DIR__.'/../storage/security/direct-entry-secret.key';
        $content = @file_get_contents($file);
        if (!is_string($content) || strlen($content) < 32) {
            throw new RuntimeException('Site security gate secret is unavailable');
        }
        return hash_hkdf('sha256',$content,32,'unrealdb-direct-entry-gate-v1');
    }

    private static function agent(): string
    {
        // Pin a proof to the observed connection source and user agent. A
        // single completed verification must not be reusable across unrelated
        // public IPs in a coordinated distributed scraping campaign.
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        return substr(hash('sha256',
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') . "\n" . $ip), 0, 24);
    }

    public static function issueToken(string $kind, int $timestamp, string $nonce): string
    {
        $base='v1.'.$timestamp.'.'.$nonce;
        $mac=hash_hmac('sha256',$kind.'|'.$base.'|'.self::agent(),self::secret());
        return $base.'.'.$mac;
    }

    public static function checkToken(string $raw,string $kind,int $minimumAge,int $maximumAge): bool
    {
        if (!preg_match('/^v1\.(\d{10})\.([a-f0-9]{16})\.([a-f0-9]{64})$/D',$raw,$m)) return false;
        $age=time()-(int)$m[1];
        if ($age<$minimumAge || $age>$maximumAge) return false;
        $expect=self::issueToken($kind,(int)$m[1],$m[2]);
        return hash_equals($expect,$raw);
    }

    private static function options(int $age): array
    {
        $secure=(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')
            || (int)($_SERVER['SERVER_PORT']??0)===443;
        return [
            'expires'=>time()+$age,'path'=>'/catalog/',
            'secure'=>$secure,'httponly'=>true,'samesite'=>'Lax'
        ];
    }

    private static function block(): never
    {
        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 60');
        echo 'Temporary access verification unavailable';
        exit;
    }

    public static function challenge(): never
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD']??'')) !== 'GET') {
            http_response_code(405);exit;
        }
        try {
            $token=self::issueToken('issue',time(),bin2hex(random_bytes(8)));
        } catch (Throwable $error) {
            error_log('[entry gate] challenge init failed: '.$error->getMessage());
            self::block();
        }
        setcookie(self::ISSUE,$token,self::options(self::ISSUE_TTL));
        header('Cache-Control: no-store, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo '{"ok":true,"wait_seconds":3}';
        exit;
    }

    public static function continueToTarget(): never
    {
        if (strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST') {
            http_response_code(405);exit;
        }
        $fetch = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE']??''));
        if ($fetch!=='' && !in_array($fetch,['same-origin','same-site'],true)) {
            http_response_code(403);exit;
        }
        $target=self::validatedReturn((string)($_POST['return']??''));
        if ($target===null) {http_response_code(400);echo 'Invalid return path';exit;}
        $fragment=(string)($_POST['anchor']??'');
        if ($fragment!=='' && preg_match('/^#[A-Za-z0-9_-]{1,80}$/D',$fragment)!==1){
            http_response_code(400);
            echo 'Invalid return anchor';
            exit;
        }
        try {
            if (!self::checkToken((string)($_COOKIE[self::ISSUE]??''),
                    'issue',self::TIME_TO_CONTINUE,self::ISSUE_TTL)) {
                http_response_code(429);
                header('Retry-After: 3');
                echo 'Verification incomplete. Return to the landing page.';
                exit;
            }
            $pass=self::issueToken('pass',time(),bin2hex(random_bytes(8)));
        } catch (Throwable $error) {
            error_log('[entry gate] validation failure: '.$error->getMessage());
            self::block();
        }
        setcookie(self::PASS,$pass,self::options(self::PASS_TTL));
        setcookie(self::ISSUE,'',self::options(-3600));
        header('Cache-Control: no-store');
        header('Location: '.$target.$fragment,true,303);
        exit;
    }

    public static function enforce(): void
    {
        if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),['GET','HEAD'],true)) return;
        $route = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!self::eligibleRoute($route)) return;
        if (self::internalClient((string)($_SERVER['REMOTE_ADDR'] ?? ''))) return;
        // An HTTP Referer is client-controlled and cannot authenticate the
        // expensive File Examine reader. Other public routes keep their
        // existing same-site/search navigation behaviour.
        if ($route !== 'file-examine.php'
            && self::trustedReferer((string)($_SERVER['HTTP_REFERER'] ?? ''))) return;
        try {
            $signedPass = (string)($_COOKIE[self::PASS] ?? '');
            if (self::checkToken($signedPass, 'pass', 0, self::PASS_TTL)) {
                if ($route === 'file-examine.php') {
                    require_once __DIR__ . '/CatalogPublicPageAdmission.php';
                    if (!CatalogPublicPageAdmission::admit($signedPass)) {
                        http_response_code(429);
                        header('Cache-Control: no-store');
                        header('Retry-After: 5');
                        header('Content-Type: text/plain; charset=utf-8');
                        echo 'The public catalogue is busy. Please retry in a few seconds.';
                        exit;
                    }
                }
                return;
            }
        } catch (Throwable $error) {
            error_log('[entry gate] unavailable: ' . $error->getMessage());
            self::block();
        }
        $original=(string)($_SERVER['REQUEST_URI']??'/catalog/');
        $target=self::validatedReturn($original);
        if ($target===null) $target='/catalog/file-examine.php';
        $raw=parse_url($target);
        $route=basename((string)($raw['path']??'/catalog/file-examine.php'));
        $query=(string)($raw['query']??'');
        header('Location: /catalog/direct-entry.html?to='.rawurlencode($route)
            .($query!==''?'&'.$query:''),true,302);
        header('Cache-Control: no-store');
        exit;
    }
}
