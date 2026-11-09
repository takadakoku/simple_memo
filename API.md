# SimpleMemo 同期 API

スマホ版（Android）と Web 版でメモ・画像をやり取りするための API です。
エンドポイントはすべて `api.php?action=...` です。

- ベース URL 例: `https://example.com/simple_memo/api.php`
- 文字コードは UTF-8、リクエスト・レスポンスとも JSON（画像の送受信を除く）
- レスポンスには必ず `ok`（true / false）が入ります。失敗時は `error` にエラーコードが入ります

## 認証

1. `login` でパスワードを送ってトークンを受け取る（有効期限 1 年）
2. 以降のリクエストに `Authorization: Bearer <token>` を付ける

トークンは端末に保存しておいてください（EncryptedSharedPreferences などを推奨）。
サーバーでパスワードを変更すると、発行済みのトークンはすべて無効になります（401 が返る → 再ログイン）。

同じ IP からログインに 10 回失敗すると 15 分間ログインできなくなります（429）。

## メモの形式

メモは 1 つのテキストです。画像は本文中に **1 行まるごと** で次のように入ります。

```
買い物メモ
[[img:0123456789abcdef0123456789abcdef.jpg]]
↑ この色
```

- 画像名は `32 桁の 16 進数 + 拡張子（jpg / png / gif / webp）`
- 同じ名前の画像の中身が変わることはありません。一度ダウンロードしたら永久にキャッシュして構いません
- 改行は `\n`

## バージョンと衝突

メモには内容から決まる `version`（16 桁の 16 進数）があります。保存するときは
「どの版を元に編集したか」を `base_version` で送ります。
サーバー側がその版から変わっていた（＝ 他の端末で先に更新された）場合、保存されずに **409** が返り、
レスポンスにサーバー側の最新内容が入っています。どちらを残すか利用者に選んでもらってください。

- 「最新を読み込む」→ レスポンスの `text` / `version` を採用
- 「この端末の内容で上書き」→ レスポンスの `version` を `base_version` にして保存し直す

空のメモの version は `e3b0c44298fc1c14` です。

---

## エンドポイント

### `POST ?action=login`

認証不要。

```json
{ "password": "..." }
```

| ステータス | 内容 |
|---|---|
| 200 | `{ "ok": true, "token": "smt1....", "expires_at": 1825239132 }`（expires_at は UNIX 秒） |
| 401 | `wrong_password` |
| 429 | `too_many_attempts`（`retry_after` 秒後に再試行） |
| 503 | `password_not_set`（サーバーでパスワード未設定） |

### `GET ?action=version`

更新確認用の軽いリクエスト。手元の version と同じならメモを取り直す必要はありません。

```json
{ "ok": true, "version": "0855b518d02dd87f", "updated_at": 1793703133 }
```

`updated_at` は最終更新の UNIX 秒（メモが一度も保存されていなければ `null`）。

### `GET ?action=memo`

```json
{
  "ok": true,
  "text": "スマホから\n[[img:230493fb20cd714eff3da281af079da0.jpg]]\n終わり",
  "version": "0855b518d02dd87f",
  "updated_at": 1793703133,
  "images": ["230493fb20cd714eff3da281af079da0.jpg"]
}
```

`images` は本文で使われている画像名の一覧です。手元にないものを `GET ?action=image` で取得してください。

### `PUT ?action=memo`（`POST` も可）

```json
{ "text": "新しい本文", "base_version": "0855b518d02dd87f" }
```

| ステータス | 内容 |
|---|---|
| 200 | 保存後の状態（`GET ?action=memo` と同じ形） |
| 400 | `text_required` / `base_version_required` / `invalid_json` |
| 409 | `conflict`。サーバー側の最新状態（`text` / `version` / `updated_at` / `images`）が入る |
| 413 | `too_large`（本文は 1MB まで） |
| 422 | `missing_images`。`missing` に、サーバーにない画像名の一覧。**画像は先にアップロードしてから本文を保存してください** |

`base_version` の代わりに `"force": true` を送ると無条件で上書きします（通常は使わないでください）。

### `POST ?action=image`

本文に画像のバイナリをそのまま送ります（multipart ではありません）。

```
Content-Type: image/jpeg
<バイナリ>
```

| ステータス | 内容 |
|---|---|
| 201 | `{ "ok": true, "name": "230493fb...jpg", "token": "[[img:230493fb...jpg]]" }` |
| 413 | `too_large`（15MB まで。`max_bytes` が入る） |
| 415 | `unsupported_image`（JPEG / PNG / GIF / WebP のみ） |

アップロードしただけでは本文に入りません。返ってきた `token` を本文に 1 行で入れて `PUT ?action=memo` してください。
本文から使われなくなった画像は、一定期間（7 日）後にサーバーから自動で削除されます。

### `GET ?action=image&name=<画像名>`

画像のバイナリを返します（`Content-Type` は画像の形式）。存在しなければ 404。

### 共通エラー

| ステータス | error |
|---|---|
| 401 | `unauthorized`（トークンなし・期限切れ・パスワード変更済み） |
| 404 | `unknown_action` |
| 405 | `method_not_allowed` |

---

## スマホ版での同期の流れ（推奨）

**アプリを開いたとき / 画面に戻ったとき**

1. `GET ?action=version`
2. 手元の version と違えば `GET ?action=memo`
   - 手元に未送信の変更がなければ、そのまま反映
   - 未送信の変更があれば、先に保存（下記）を試みて 409 なら利用者に選んでもらう
3. `images` のうち手元にない画像を `GET ?action=image` でダウンロードしてキャッシュ

**編集したとき**（Web 版と同じく、入力が止まって少し経ってから）

1. 新しく追加した画像があれば `POST ?action=image` でアップロードし、本文の該当行を `[[img:名前]]` にする
2. `PUT ?action=memo`（`base_version` = 最後にサーバーと一致していた version）
3. 200 なら返ってきた `version` を保存。409 なら衝突処理、通信エラーなら未送信として残して後で再送

オフラインでも編集できるよう、本文と version・未送信フラグは端末内にも保存しておくのがおすすめです。

## 例（curl）

```sh
BASE=https://example.com/simple_memo/api.php
TOKEN=$(curl -s -X POST -d '{"password":"..."}' "$BASE?action=login" | jq -r .token)

curl -s -H "Authorization: Bearer $TOKEN" "$BASE?action=memo"
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: image/jpeg' --data-binary @photo.jpg "$BASE?action=image"
curl -s -H "Authorization: Bearer $TOKEN" -X PUT \
  -d '{"text":"メモ\n[[img:...jpg]]","base_version":"0855b518d02dd87f"}' "$BASE?action=memo"
```
