<?php
declare(strict_types=1);

// 認証まわり。
//  - Web版：署名付き Cookie（1年）。PHP セッションは gc で短時間に消えるため使わない
//  - API（スマホ版）：署名付きトークン（1年）を Authorization: Bearer で送る
// どちらもパスワードを変更すると secret が作り直され、すべて無効になる。

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/lib.php';

const AUTH_FILE     = DATA_DIR . '/auth.php'; // .php にしておけば万一直接開かれても中身は出ない
const AUTH_COOKIE   = 'simplememo_auth';
const AUTH_LIFETIME = 365 * 24 * 60 * 60; // 1年
const THROTTLE_FILE = DATA_DIR . '/login_failures.php';
const THROTTLE_MAX  = 10;      // この回数失敗したら
const THROTTLE_WINDOW = 900;   // この秒数ロックする（IP ごと）

// 設定ファイルは「PHP ガード + JSON」形式。include すると opcache に古い内容が残ることがあるので、
// 普通のファイルとして読む。（直接アクセスされてもガードで中身は出ない）
const FILE_GUARD = "<?php http_response_code(404); exit; ?>\n";

function readDataFile(string $path): ?array
{
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false) {
        return null;
    }
    if (str_starts_with($raw, FILE_GUARD)) {
        $data = json_decode(substr($raw, strlen(FILE_GUARD)), true);
        return is_array($data) ? $data : null;
    }
    // 旧形式（return [...];）
    if (str_starts_with($raw, "<?php\nreturn ")) {
        $data = include $path;
        return is_array($data) ? $data : null;
    }
    return null;
}

function writeDataFile(string $path, array $data, int $mode = 0640): bool
{
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, FILE_GUARD . json_encode($data)) === false) {
        return false;
    }
    chmod($tmp, $mode);
    return rename($tmp, $path);
}

/** @return array{hash:string,secret:string}|null */
function authConfig(): ?array
{
    static $conf = false;
    if ($conf !== false) {
        return $conf;
    }
    $loaded = readDataFile(AUTH_FILE);
    $conf = (is_array($loaded) && !empty($loaded['hash']) && !empty($loaded['secret'])) ? $loaded : null;
    return $conf;
}

function writeAuthConfig(string $password): bool
{
    $conf = [
        'hash'   => password_hash($password, PASSWORD_DEFAULT),
        'secret' => bin2hex(random_bytes(32)),
    ];
    return writeDataFile(AUTH_FILE, $conf);
}

function authSign(string $payload, array $conf): string
{
    return hash_hmac('sha256', $payload, $conf['secret'] . $conf['hash']);
}

// ---- ログイン失敗の回数制限 ----

/** @return array<string, list<int>> */
function loadFailures(): array
{
    return readDataFile(THROTTLE_FILE) ?? [];
}

function clientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function isThrottled(): bool
{
    $times = loadFailures()[clientIp()] ?? [];
    $recent = array_filter($times, fn($t) => $t > time() - THROTTLE_WINDOW);
    return count($recent) >= THROTTLE_MAX;
}

function recordFailure(): void
{
    $fp = fopen(THROTTLE_FILE . '.lock', 'c');
    if ($fp === false) {
        return;
    }
    flock($fp, LOCK_EX);
    $data = loadFailures();
    $now = time();
    foreach ($data as $ip => $times) {
        $data[$ip] = array_values(array_filter($times, fn($t) => $t > $now - THROTTLE_WINDOW));
        if (!$data[$ip]) {
            unset($data[$ip]);
        }
    }
    $data[clientIp()][] = $now;
    writeDataFile(THROTTLE_FILE, $data, 0660);
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** パスワードを確認する。'ok' | 'wrong' | 'throttled' | 'unconfigured' */
function checkPassword(string $password): string
{
    $conf = authConfig();
    if ($conf === null) {
        return 'unconfigured';
    }
    if (isThrottled()) {
        return 'throttled';
    }
    if (!password_verify($password, $conf['hash'])) {
        recordFailure();
        sleep(1); // 総当たり対策
        return 'wrong';
    }
    return 'ok';
}

// ---- Web版（Cookie） ----

function cookieOptions(int $expires): array
{
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
    return [
        'expires'  => $expires,
        'path'     => $path,
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** "期限.署名" 形式の値を検証する */
function verifySigned(string $value, string $purpose): bool
{
    $conf = authConfig();
    if ($conf === null || !str_contains($value, '.')) {
        return false;
    }
    [$expires, $sig] = explode('.', $value, 2);
    if (!ctype_digit($expires) || (int)$expires < time()) {
        return false;
    }
    return hash_equals(authSign($purpose . $expires, $conf), $sig);
}

function isLoggedIn(): bool
{
    $cookie = $_COOKIE[AUTH_COOKIE] ?? '';
    return is_string($cookie) && verifySigned($cookie, '');
}

/** Web版のログイン。'ok' | 'wrong' | 'throttled' | 'unconfigured' */
function login(string $password): string
{
    $result = checkPassword($password);
    if ($result === 'ok') {
        $expires = time() + AUTH_LIFETIME;
        $value = $expires . '.' . authSign((string)$expires, authConfig());
        setcookie(AUTH_COOKIE, $value, cookieOptions($expires));
    }
    return $result;
}

function logout(): void
{
    setcookie(AUTH_COOKIE, '', cookieOptions(time() - 3600));
}

// ---- API（Bearer トークン） ----

const TOKEN_PREFIX = 'smt1.';

/** @return array{token:string,expires_at:int} */
function issueApiToken(): array
{
    $expires = time() + AUTH_LIFETIME;
    return [
        'token'      => TOKEN_PREFIX . $expires . '.' . authSign('api:' . $expires, authConfig()),
        'expires_at' => $expires,
    ];
}

function bearerToken(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $header = array_change_key_case(getallheaders())['authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : '';
}

function isApiAuthorized(): bool
{
    $token = bearerToken();
    return str_starts_with($token, TOKEN_PREFIX) && verifySigned(substr($token, strlen(TOKEN_PREFIX)), 'api:');
}
