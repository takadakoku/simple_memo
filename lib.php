<?php
declare(strict_types=1);

// メモと画像の保存処理（Web版 index.php と API api.php で共通）

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

// SIMPLEMEMO_DATA_DIR はテスト用（本番では設定しない）
define('DATA_DIR', getenv('SIMPLEMEMO_DATA_DIR') ?: __DIR__ . '/data');
// .php にしておき、先頭のガードで直接アクセスされても中身が出ないようにする
const MEMO_FILE  = DATA_DIR . '/memo.php';
const MEMO_GUARD = "<?php http_response_code(404); exit; ?>\n";
const LOCK_FILE  = DATA_DIR . '/memo.lock';
const MAX_BYTES  = 1024 * 1024; // 1MB
const IMAGE_DIR  = DATA_DIR . '/images';
const IMAGE_MAX_BYTES = 15 * 1024 * 1024; // 15MB
const IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];
const IMAGE_NAME_RE  = '/^[a-f0-9]{32}\.(?:jpg|png|gif|webp)$/';
const IMAGE_TOKEN_RE = '/\[\[img:([a-f0-9]{32}\.(?:jpg|png|gif|webp))\]\]/';
// 本文から外された画像は .orphan に退避し、この日数を過ぎたら削除（元に戻す操作に備える）
const ORPHAN_DAYS = 7;

function loadMemo(): string
{
    if (!is_file(MEMO_FILE)) {
        return '';
    }
    $text = file_get_contents(MEMO_FILE);
    if ($text === false) {
        return '';
    }
    return str_starts_with($text, MEMO_GUARD) ? substr($text, strlen(MEMO_GUARD)) : $text;
}

/** 内容から決まるバージョン。端末間で「どの内容を元に編集したか」を照合するのに使う */
function memoVersion(string $text): string
{
    return substr(hash('sha256', $text), 0, 16);
}

/** @return list<string> 本文で使われている画像名（重複なし・出現順） */
function referencedImages(string $text): array
{
    preg_match_all(IMAGE_TOKEN_RE, $text, $m);
    return array_values(array_unique($m[1]));
}

/** @return array{text:string,version:string,updated_at:int|null,images:list<string>} */
function memoState(?string $text = null): array
{
    $text ??= loadMemo();
    clearstatcache(true, MEMO_FILE);
    return [
        'text'       => $text,
        'version'    => memoVersion($text),
        'updated_at' => is_file(MEMO_FILE) ? filemtime(MEMO_FILE) : null,
        'images'     => referencedImages($text),
    ];
}

function writeMemoFile(string $text): bool
{
    // 一時ファイルに書いてから rename して、書き込み途中の状態を読まれないようにする
    $tmp = MEMO_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, MEMO_GUARD . $text) === false) {
        return false;
    }
    return rename($tmp, MEMO_FILE);
}

/**
 * メモを保存する。$baseVersion を渡すと、サーバー側がその版から変わっていた場合は保存せず衝突を返す。
 * @return array{status:'ok'|'conflict'|'error',state:array}
 */
function saveMemo(string $text, ?string $baseVersion = null): array
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0775, true)) {
        return ['status' => 'error', 'state' => []];
    }
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return ['status' => 'error', 'state' => []];
    }
    try {
        $current = loadMemo();
        if ($current === $text) {
            return ['status' => 'ok', 'state' => memoState($current)];
        }
        if ($baseVersion !== null && !hash_equals(memoVersion($current), $baseVersion)) {
            return ['status' => 'conflict', 'state' => memoState($current)];
        }
        if (!writeMemoFile($text)) {
            return ['status' => 'error', 'state' => []];
        }
        cleanupImages($text);
        return ['status' => 'ok', 'state' => memoState($text)];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function imagePath(string $name): ?string
{
    if (!preg_match(IMAGE_NAME_RE, $name)) {
        return null;
    }
    $path = IMAGE_DIR . '/' . $name;
    if (is_file($path)) {
        return $path;
    }
    return is_file($path . '.orphan') ? $path . '.orphan' : null;
}

/** 画像を保存してファイル名を返す。不正な画像なら null */
function saveImage(string $bin): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bin);
    if (!isset(IMAGE_TYPES[$mime]) || @getimagesizefromstring($bin) === false) {
        return null;
    }
    if (!is_dir(IMAGE_DIR) && !mkdir(IMAGE_DIR, 02775, true)) {
        return null;
    }
    $name = bin2hex(random_bytes(16)) . '.' . IMAGE_TYPES[$mime];
    return file_put_contents(IMAGE_DIR . '/' . $name, $bin, LOCK_EX) === false ? null : $name;
}

/** 本文で使われていない画像を退避・削除し、再び使われた画像は復帰させる */
function cleanupImages(string $text): void
{
    if (!is_dir(IMAGE_DIR)) {
        return;
    }
    $used = array_flip(referencedImages($text));
    foreach (scandir(IMAGE_DIR) ?: [] as $file) {
        $path = IMAGE_DIR . '/' . $file;
        if (preg_match(IMAGE_NAME_RE, $file)) {
            // アップロード直後で、まだ本文に入っていない画像は対象外にする
            if (!isset($used[$file]) && filemtime($path) < time() - 3600) {
                rename($path, $path . '.orphan');
                touch($path . '.orphan');
            }
        } elseif (str_ends_with($file, '.orphan')) {
            $name = substr($file, 0, -7);
            if (isset($used[$name])) {
                rename($path, IMAGE_DIR . '/' . $name);
            } elseif (filemtime($path) < time() - ORPHAN_DAYS * 86400) {
                unlink($path);
            }
        }
    }
}

function serveImage(string $name): never
{
    $path = imagePath($name);
    if ($path === null) {
        http_response_code(404);
        exit;
    }
    $mime = array_search(pathinfo($name, PATHINFO_EXTENSION), IMAGE_TYPES, true);
    header_remove('Cache-Control');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    // 画像名は内容ごとに新しく発行され、同じ名前の中身が変わることはない
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: "' . $name . '"');
    readfile($path);
    exit;
}

function jsonExit(int $code, array $body): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
