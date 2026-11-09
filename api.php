<?php
declare(strict_types=1);

// スマホ版（Android）との同期用 API。仕様は API.md を参照。

require __DIR__ . '/auth.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');

function apiError(int $code, string $error, array $extra = []): never
{
    jsonExit($code, ['ok' => false, 'error' => $error] + $extra);
}

function requireMethod(string ...$allowed): void
{
    global $method;
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        apiError(405, 'method_not_allowed');
    }
}

/** @return array<string,mixed> */
function jsonBody(): array
{
    $raw = file_get_contents('php://input', false, null, 0, MAX_BYTES * 2 + 1);
    if ($raw === false || strlen($raw) > MAX_BYTES * 2) {
        apiError(413, 'too_large');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        apiError(400, 'invalid_json');
    }
    return $data;
}

// ---- ログイン（認証不要） ----
if ($action === 'login') {
    requireMethod('POST');
    $password = jsonBody()['password'] ?? null;
    if (!is_string($password) || $password === '') {
        apiError(400, 'password_required');
    }
    match (checkPassword($password)) {
        'ok'           => jsonExit(200, ['ok' => true] + issueApiToken()),
        'throttled'    => apiError(429, 'too_many_attempts', ['retry_after' => THROTTLE_WINDOW]),
        'unconfigured' => apiError(503, 'password_not_set'),
        default        => apiError(401, 'wrong_password'),
    };
}

// ---- ここから先はトークン必須 ----
if (!isApiAuthorized()) {
    header('WWW-Authenticate: Bearer');
    apiError(401, 'unauthorized');
}

switch ($action) {
    // 軽い更新確認。version が手元と同じなら memo を取り直す必要はない
    case 'version':
        requireMethod('GET');
        $state = memoState();
        jsonExit(200, ['ok' => true, 'version' => $state['version'], 'updated_at' => $state['updated_at']]);

    case 'memo':
        requireMethod('GET', 'PUT', 'POST');
        if ($method === 'GET') {
            jsonExit(200, ['ok' => true] + memoState());
        }

        $body = jsonBody();
        $text = $body['text'] ?? null;
        $base = $body['base_version'] ?? null;
        $force = ($body['force'] ?? false) === true;
        if (!is_string($text)) {
            apiError(400, 'text_required');
        }
        if (strlen($text) > MAX_BYTES) {
            apiError(413, 'too_large');
        }
        if (!$force && !is_string($base)) {
            apiError(400, 'base_version_required');
        }
        // 画像は先にアップロードしてから本文を保存する決まり
        $missing = array_values(array_filter(referencedImages($text), fn($n) => imagePath($n) === null));
        if ($missing) {
            apiError(422, 'missing_images', ['missing' => $missing]);
        }
        $result = saveMemo($text, $force ? null : $base);
        match ($result['status']) {
            'ok'       => jsonExit(200, ['ok' => true] + $result['state']),
            'conflict' => apiError(409, 'conflict', $result['state']),
            default    => apiError(500, 'save_failed'),
        };

    case 'image':
        requireMethod('GET', 'POST');
        if ($method === 'GET') {
            serveImage((string)($_GET['name'] ?? ''));
        }
        $bin = file_get_contents('php://input', false, null, 0, IMAGE_MAX_BYTES + 1);
        if ($bin === false || $bin === '') {
            apiError(400, 'empty');
        }
        if (strlen($bin) > IMAGE_MAX_BYTES) {
            apiError(413, 'too_large', ['max_bytes' => IMAGE_MAX_BYTES]);
        }
        $name = saveImage($bin);
        if ($name === null) {
            apiError(415, 'unsupported_image', ['allowed' => array_keys(IMAGE_TYPES)]);
        }
        jsonExit(201, ['ok' => true, 'name' => $name, 'token' => '[[img:' . $name . ']]']);

    default:
        apiError(404, 'unknown_action');
}
