<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

function css(): string
{
    return <<<'CSS'
  :root {
    --bg: #fef7ff;
    --fg: #1d1b20;
    --placeholder: #79747e;
    --status: #79747e;
    --accent: #6750a4;
    --on-accent: #ffffff;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #141218;
      --fg: #e6e0e9;
      --placeholder: #938f99;
      --status: #938f99;
      --accent: #d0bcff;
      --on-accent: #381e72;
    }
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    height: 100%;
    background: var(--bg);
    color: var(--fg);
  }
  #memo {
    min-height: 100%;
    margin: 0;
    padding: calc(28px + env(safe-area-inset-top)) max(16px, env(safe-area-inset-right))
             max(48px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
    outline: none;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font: 16px/1.5 system-ui, -apple-system, "Hiragino Sans", "Noto Sans JP", sans-serif;
  }
  #memo.empty::before {
    content: attr(data-placeholder);
    color: var(--placeholder);
    pointer-events: none;
  }
  #memo img {
    display: inline-block;
    vertical-align: bottom;
    max-width: 100%;
    max-height: 80vh;
    margin: 4px 0;
    border-radius: 8px;
  }
  #memo img.uploading { opacity: .4; }
  #memo.dragover { outline: 2px dashed var(--accent); outline-offset: -8px; }
  #status {
    position: fixed;
    right: 12px;
    bottom: 8px;
    font: 12px system-ui, sans-serif;
    color: var(--status);
    pointer-events: none;
    opacity: 0;
    transition: opacity .3s;
  }
  #status.show { opacity: 1; }
  #status.error { color: #b3261e; opacity: 1; }
  .login {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    padding: 16px;
  }
  .login form { width: 100%; max-width: 320px; }
  .login h1 { font: 500 22px system-ui, sans-serif; margin: 0 0 16px; text-align: center; }
  .login input, .login button {
    display: block;
    width: 100%;
    padding: 12px 14px;
    font: 16px system-ui, sans-serif;
    border-radius: 8px;
  }
  .login input {
    border: 1px solid var(--placeholder);
    background: transparent;
    color: inherit;
  }
  .login input:focus { outline: 2px solid var(--accent); border-color: transparent; }
  .login button {
    margin-top: 12px;
    border: none;
    background: var(--accent);
    color: var(--on-accent);
    cursor: pointer;
  }
  .login .error { color: #b3261e; font: 14px system-ui, sans-serif; margin: 8px 0 0; }
  .login p { font: 14px/1.6 system-ui, sans-serif; }
  #conflict {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    display: none;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    padding: 12px max(16px, env(safe-area-inset-right)) max(12px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
    background: var(--accent);
    color: var(--on-accent);
    font: 14px/1.4 system-ui, sans-serif;
  }
  #conflict.show { display: flex; }
  #conflict span { flex: 1 1 200px; }
  #conflict button {
    padding: 6px 12px;
    border: 1px solid currentColor;
    border-radius: 6px;
    background: transparent;
    color: inherit;
    font: inherit;
    cursor: pointer;
  }
  #tools {
    position: fixed;
    top: max(6px, env(safe-area-inset-top));
    right: max(10px, env(safe-area-inset-right));
    display: flex;
    gap: 12px;
    font: 12px system-ui, sans-serif;
  }
  #tools a, #tools button {
    color: var(--status);
    background: none;
    border: none;
    padding: 0;
    font: inherit;
    text-decoration: none;
    cursor: pointer;
    opacity: .5;
  }
  #tools a:hover, #tools button:hover { opacity: 1; }
CSS;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function renderLogin(string $error): void
{
    $configured = authConfig() !== null;
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<title>SimpleMemo - ログイン</title>
<style>
<?= css() ?>
</style>
</head>
<body>
<div class="login">
<?php if ($configured): ?>
  <form method="post">
    <h1>SimpleMemo</h1>
    <input type="password" name="password" placeholder="パスワード" autocomplete="current-password" autofocus required>
    <button type="submit">ログイン</button>
    <?php if ($error !== ''): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  </form>
<?php else: ?>
  <div>
    <h1>SimpleMemo</h1>
    <p>パスワードがまだ設定されていません。サーバーで次のコマンドを実行してください。</p>
    <p><code>php set_password.php</code></p>
  </div>
<?php endif; ?>
</div>
</body>
</html>
<?php
}

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

$self = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

// ログアウト
if (isset($_GET['logout'])) {
    logout();
    header('Location: ' . $self);
    exit;
}

// fetch からのリクエスト。カスタムヘッダは同一オリジンの fetch でしか付けられないので、他サイトからの書き込みも防げる
$isApi = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'SimpleMemo';

// ログインフォームの送信
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isApi) {
    $result = login((string)($_POST['password'] ?? ''));
    if ($result === 'ok') {
        header('Location: ' . $self, true, 303);
        exit;
    }
    $loginError = match ($result) {
        'throttled' => 'ログインの失敗が続いたため、15分ほど時間をおいてください',
        default     => 'パスワードが違います',
    };
}

// 未ログインならログイン画面
if (!isLoggedIn()) {
    if ($isApi) {
        jsonExit(401, ['ok' => false, 'error' => 'unauthorized']);
    }
    if (isset($_GET['img'])) {
        http_response_code(401);
        exit;
    }
    http_response_code(authConfig() === null ? 503 : ($loginError ? 401 : 200));
    if ($loginError !== '' && str_contains($loginError, '15分')) {
        http_response_code(429);
    }
    renderLogin($loginError);
    exit;
}

// 画像の表示（ログイン必須）
if (isset($_GET['img'])) {
    serveImage((string)$_GET['img']);
}

// 最新の状態（他の端末で更新されていないかの確認用）
if (($_GET['action'] ?? '') === 'state' && $isApi) {
    jsonExit(200, ['ok' => true] + memoState());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isApi) {
        jsonExit(403, ['ok' => false, 'error' => 'forbidden']);
    }

    // 画像のアップロード
    if (($_GET['action'] ?? '') === 'upload') {
        $bin = file_get_contents('php://input', false, null, 0, IMAGE_MAX_BYTES + 1);
        if ($bin === false || $bin === '') {
            jsonExit(400, ['ok' => false, 'error' => 'empty']);
        }
        if (strlen($bin) > IMAGE_MAX_BYTES) {
            jsonExit(413, ['ok' => false, 'error' => 'too large']);
        }
        $name = saveImage($bin);
        if ($name === null) {
            jsonExit(415, ['ok' => false, 'error' => 'unsupported']);
        }
        jsonExit(200, ['ok' => true, 'name' => $name]);
    }

    // メモの自動保存
    $text = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
    if ($text === false || strlen($text) > MAX_BYTES) {
        jsonExit(413, ['ok' => false, 'error' => 'too large']);
    }
    $base = $_SERVER['HTTP_X_BASE_VERSION'] ?? null;
    $result = saveMemo($text, is_string($base) && $base !== '' ? $base : null);
    match ($result['status']) {
        'ok'       => jsonExit(200, ['ok' => true, 'version' => $result['state']['version']]),
        'conflict' => jsonExit(409, ['ok' => false, 'error' => 'conflict'] + $result['state']),
        default    => jsonExit(500, ['ok' => false, 'error' => 'save failed']),
    };
}

$state = memoState();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex">
<title>SimpleMemo</title>
<style>
<?= css() ?>
</style>
</head>
<body>
<div id="memo" contenteditable="true" spellcheck="false" data-placeholder="ここにメモを入力"></div>
<div id="tools">
  <button type="button" id="add-image">画像</button>
  <a href="?logout=1">ログアウト</a>
</div>
<input type="file" id="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
<div id="status" aria-live="polite"></div>
<div id="conflict" role="alert">
  <span>他の端末でメモが更新されています。どちらの内容を残しますか？</span>
  <button type="button" id="take-remote">最新の内容を読み込む</button>
  <button type="button" id="keep-local">この画面の内容で上書き</button>
</div>
<script id="initial" type="application/json"><?= json_encode(['text' => $state['text'], 'version' => $state['version']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script>
(() => {
  const memo = document.getElementById('memo');
  const status = document.getElementById('status');
  const fileInput = document.getElementById('file');
  const API_HEADERS = { 'X-Requested-With': 'SimpleMemo' };
  const TOKEN_RE = /\[\[img:([a-f0-9]{32}\.(?:jpg|png|gif|webp))\]\]\n?/g;
  const RESIZE_OVER = 10 * 1024 * 1024; // これより大きい画像は縮小してから送る
  const RESIZE_MAX_SIDE = 3000;

  let timer = null;
  let saving = false;
  let pending = false;
  let dirty = false;
  let uploads = 0;
  let lastRange = null;

  function show(msg, isError = false) {
    status.textContent = msg;
    status.className = isError ? 'error' : 'show';
    clearTimeout(show.t);
    if (!isError) show.t = setTimeout(() => { status.className = ''; }, 1200);
  }

  // ---- 本文 <-> テキスト（画像は [[img:名前]] の行として保存） ----
  function imgEl(name) {
    const img = document.createElement('img');
    img.src = '?img=' + encodeURIComponent(name);
    img.dataset.name = name;
    img.alt = '';
    return img;
  }

  function render(text) {
    memo.textContent = '';
    let last = 0;
    for (const m of text.matchAll(TOKEN_RE)) {
      if (m.index > last) memo.append(text.slice(last, m.index));
      memo.append(imgEl(m[1]), document.createElement('br'));
      last = m.index + m[0].length;
    }
    if (last < text.length) memo.append(text.slice(last));
  }

  const BLOCKS = new Set(['DIV', 'P', 'LI', 'UL', 'OL', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BLOCKQUOTE', 'PRE']);
  function serialize() {
    let out = '';
    let afterBlock = false;
    const nl = () => { if (out && !out.endsWith('\n')) out += '\n'; };
    const walk = (node) => {
      for (const c of node.childNodes) {
        if (c.nodeType === Node.TEXT_NODE) {
          if (afterBlock && c.data) { nl(); afterBlock = false; }
          out += c.data;
        } else if (c.nodeName === 'BR') {
          afterBlock = false;
          // 画像の直後の改行は表示用なので保存しない（画像トークン側で改行済み）
          if (c.previousSibling?.nodeName === 'IMG' && c.previousSibling.dataset.name) continue;
          out += '\n';
        } else if (c.nodeName === 'IMG') {
          if (!c.dataset.name) continue; // アップロード中
          nl();
          out += '[[img:' + c.dataset.name + ']]\n';
          afterBlock = false;
        } else if (BLOCKS.has(c.nodeName)) {
          nl();
          walk(c);
          afterBlock = true;
        } else {
          walk(c);
        }
      }
    };
    walk(memo);
    return out.trim() === '' ? '' : out;
  }

  function updatePlaceholder() {
    const empty = !memo.querySelector('img') && memo.textContent === '';
    memo.classList.toggle('empty', empty);
  }

  // ---- 保存 ----
  // version は「この画面の内容がどの版を元にしているか」。保存時に送り、
  // サーバー側（スマホなど）で先に更新されていたら 409 が返る。
  const INITIAL = JSON.parse(document.getElementById('initial').textContent);
  let version = INITIAL.version;
  let conflict = null; // 衝突中はサーバー側の最新状態を保持し、自動保存を止める
  const conflictBar = document.getElementById('conflict');

  function postMemo(body, keepalive = false) {
    return fetch(location.pathname, {
      method: 'POST',
      headers: { ...API_HEADERS, 'Content-Type': 'text/plain; charset=utf-8', 'X-Base-Version': version },
      body,
      keepalive,
    });
  }

  // 保存リクエストの結果を処理する。成功なら true
  async function handleSaveResponse(res) {
    if (res.status === 401) {
      show('ログインの有効期限が切れました。再読み込みしてログインしてください', true);
      return false;
    }
    const json = await res.json().catch(() => ({}));
    if (res.status === 409) {
      conflict = json;
      conflictBar.classList.add('show');
      return false;
    }
    if (!res.ok) throw new Error(res.status);
    version = json.version;
    return true;
  }

  async function save() {
    if (conflict) return;
    if (saving) { pending = true; return; }
    saving = true;
    dirty = false;
    try {
      const ok = await handleSaveResponse(await postMemo(serialize()));
      if (!ok) dirty = true;
      else if (!uploads) show('保存しました');
    } catch (e) {
      dirty = true;
      show('保存に失敗しました', true);
    } finally {
      saving = false;
      if (pending) { pending = false; save(); }
    }
  }

  function scheduleSave() {
    dirty = true;
    updatePlaceholder();
    clearTimeout(timer);
    if (!conflict) timer = setTimeout(save, 400);
  }

  memo.addEventListener('input', scheduleSave);

  // 衝突の解決
  document.getElementById('take-remote').addEventListener('click', () => {
    render(conflict.text);
    version = conflict.version;
    conflict = null;
    dirty = false;
    conflictBar.classList.remove('show');
    updatePlaceholder();
    memo.focus();
    caretToEnd();
    show('最新の内容を読み込みました');
  });
  document.getElementById('keep-local').addEventListener('click', () => {
    version = conflict.version;
    conflict = null;
    conflictBar.classList.remove('show');
    save();
  });

  // 画面を離れる時に未保存分を送る（keepalive は 64KB までなので、それ以上は通常送信）
  const flush = () => {
    if (!dirty || conflict || saving) return;
    clearTimeout(timer);
    const body = serialize();
    dirty = false;
    postMemo(body, new Blob([body]).size < 60000)
      .then(handleSaveResponse)
      .then((ok) => { if (!ok) dirty = true; })
      .catch(() => { dirty = true; });
  };

  // 画面に戻ってきた時、他の端末で更新されていれば読み込む
  async function checkRemote() {
    if (dirty || saving || uploads || conflict) {
      if (dirty && !saving && !conflict) save();
      return;
    }
    try {
      const res = await fetch(location.pathname + '?action=state', { headers: API_HEADERS });
      if (!res.ok) return;
      const json = await res.json();
      if (json.version === version || dirty || saving || uploads || conflict) return;
      const hadFocus = document.activeElement === memo;
      render(json.text);
      version = json.version;
      updatePlaceholder();
      if (hadFocus) caretToEnd();
      show('他の端末の変更を読み込みました');
    } catch (e) { /* オフラインなど。次の機会に再確認 */ }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
    else checkRemote();
  });
  window.addEventListener('focus', checkRemote);
  window.addEventListener('pagehide', flush);
  window.addEventListener('beforeunload', (e) => { if (uploads) e.preventDefault(); });

  // ---- 画像の追加 ----
  async function shrink(file) {
    if (file.size <= RESIZE_OVER || file.type === 'image/gif') return file;
    const bmp = await createImageBitmap(file);
    const scale = Math.min(1, RESIZE_MAX_SIDE / Math.max(bmp.width, bmp.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
    return await new Promise((ok) => canvas.toBlob(ok, 'image/jpeg', 0.88));
  }

  let tmpId = 0;
  // ブラウザの編集コマンドで挿入する（「元に戻す」が効くように）
  function insertImageAtCaret(src) {
    const sel = getSelection();
    if (!lastRange || !memo.contains(lastRange.commonAncestorContainer)) {
      lastRange = document.createRange();
      lastRange.selectNodeContents(memo);
      lastRange.collapse(false);
    }
    memo.focus();
    sel.removeAllRanges();
    sel.addRange(lastRange);
    const id = 't' + (++tmpId);
    document.execCommand('insertHTML', false, `<img class="uploading" data-tmp="${id}" src="${src}"><br>`);
    return memo.querySelector(`img[data-tmp="${id}"]`);
  }

  async function addImages(files) {
    for (const file of files) {
      if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type)) {
        show('対応していない形式です（JPEG / PNG / GIF / WebP）', true);
        continue;
      }
      const blobUrl = URL.createObjectURL(file);
      const preview = insertImageAtCaret(blobUrl);
      if (!preview) { URL.revokeObjectURL(blobUrl); continue; }
      updatePlaceholder();
      uploads++;
      show('画像をアップロード中…');
      try {
        const body = await shrink(file);
        const res = await fetch(location.pathname + '?action=upload', {
          method: 'POST',
          headers: { ...API_HEADERS, 'Content-Type': body.type || 'application/octet-stream' },
          body,
        });
        const json = await res.json().catch(() => ({}));
        if (res.status === 401) throw new Error('ログインの有効期限が切れました');
        if (res.status === 413) throw new Error('画像が大きすぎます');
        if (!res.ok || !json.name) throw new Error('画像のアップロードに失敗しました');
        preview.dataset.name = json.name;
        preview.removeAttribute('data-tmp');
        preview.removeAttribute('class');
        const real = new Image();
        real.onload = () => { preview.src = real.src; URL.revokeObjectURL(blobUrl); };
        real.src = '?img=' + encodeURIComponent(json.name);
        uploads--;
        scheduleSave();
      } catch (e) {
        uploads--;
        URL.revokeObjectURL(blobUrl);
        if (preview.nextSibling?.nodeName === 'BR') preview.nextSibling.remove();
        preview.remove();
        updatePlaceholder();
        show(e.message || '画像のアップロードに失敗しました', true);
      }
    }
  }

  // カーソル位置を覚えておく（ボタンを押すとフォーカスが外れるため）
  document.addEventListener('selectionchange', () => {
    const sel = getSelection();
    if (sel.rangeCount && memo.contains(sel.anchorNode)) lastRange = sel.getRangeAt(0).cloneRange();
  });

  document.getElementById('add-image').addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => {
    addImages([...fileInput.files]);
    fileInput.value = '';
  });

  // 貼り付け：画像はアップロード、文字は書式を捨ててプレーンテキストで
  memo.addEventListener('paste', (e) => {
    const files = [...e.clipboardData.files].filter((f) => f.type.startsWith('image/'));
    e.preventDefault();
    if (files.length) {
      addImages(files);
      return;
    }
    const text = e.clipboardData.getData('text/plain');
    if (text) document.execCommand('insertText', false, text);
  });

  // ドラッグ&ドロップ
  memo.addEventListener('dragover', (e) => {
    if (![...e.dataTransfer.types].includes('Files')) return;
    e.preventDefault();
    memo.classList.add('dragover');
  });
  memo.addEventListener('dragleave', () => memo.classList.remove('dragover'));
  memo.addEventListener('drop', (e) => {
    memo.classList.remove('dragover');
    const files = [...e.dataTransfer.files].filter((f) => f.type.startsWith('image/'));
    if (!files.length) return;
    e.preventDefault();
    const pos = document.caretPositionFromPoint?.(e.clientX, e.clientY);
    const r = pos ? document.createRange() : document.caretRangeFromPoint?.(e.clientX, e.clientY);
    if (pos) { r.setStart(pos.offsetNode, pos.offset); r.collapse(true); }
    if (r && memo.contains(r.startContainer)) lastRange = r;
    addImages(files);
  });

  // ---- 初期表示 ----
  function caretToEnd() {
    const end = document.createRange();
    end.selectNodeContents(memo);
    end.collapse(false);
    getSelection().removeAllRanges();
    getSelection().addRange(end);
  }

  render(INITIAL.text);
  updatePlaceholder();
  memo.focus();
  caretToEnd();
})();
</script>
</body>
</html>
