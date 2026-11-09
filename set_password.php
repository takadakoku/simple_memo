<?php
declare(strict_types=1);

// パスワード設定・変更用（コマンドラインからのみ実行可）
//   php set_password.php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/auth.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return rtrim((string)$line, "\r\n");
}

$pw1 = prompt('新しいパスワード: ');
if (strlen($pw1) < 8) {
    fwrite(STDERR, "8文字以上にしてください\n");
    exit(1);
}
$pw2 = prompt('もう一度入力: ');
if (!hash_equals($pw1, $pw2)) {
    fwrite(STDERR, "パスワードが一致しません\n");
    exit(1);
}

if (!is_dir(dirname(AUTH_FILE))) {
    mkdir(dirname(AUTH_FILE), 0775, true);
}
if (!writeAuthConfig($pw1)) {
    fwrite(STDERR, "保存に失敗しました: " . AUTH_FILE . "\n");
    exit(1);
}
echo "パスワードを設定しました（既存のログインはすべて無効になりました）\n";
