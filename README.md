# ec-cube2-ecauth

EC-CUBE 2 系向け EcAuth 認証プラグイン。
B2B パスキー（管理画面ログイン）と B2C ソーシャルログイン（OIDC フェデレーション）を提供する。

## 対応バージョン

- EC-CUBE 2.13, 2.17, 2.25
- PHP 7.4+ (8.x 動作確認)
- PostgreSQL / MySQL

## 機能

| 機能 | 対象 | 認証方式 |
|---|---|---|
| 管理画面パスキーログイン | EC-CUBE 管理者 | WebAuthn / FIDO2 + EcAuth /v1/b2b/passkey/* |
| 管理画面パスキー管理 | EC-CUBE 管理者 | パスワード再認証 + パスキー登録 / 削除 |
| 顧客ソーシャルログイン | EC-CUBE フロント顧客 | OIDC PKCE フロー（Google / LINE 等の外部 IdP） |

## インストール

### 本番（管理画面 Web インストール）

1. [Releases](../../releases) から `EcAuthLogin2-X.Y.Z.tar.gz` をダウンロード（または自前で `./tools/build-archive.sh` で作成）。
2. EC-CUBE 管理画面 > **オーナーズストア > プラグイン > プラグインを追加する** から tar.gz をアップロードする。
3. プラグインを **有効化** する。
4. プラグイン一覧の **「設定」** リンクから `Client ID` / `Client Secret` を入力して保存。
5. （B2B のみ）管理画面 **オーナーズストア > プラグイン > パスキー管理** から「パスキーを追加」してパスワード再認証 → パスキー登録。
6. ログアウトすると、次回のログイン画面に **「パスキーでログイン」** ボタンが表示される。

> **重要**: 本番経路は管理画面からの tar.gz アップロードのみ。`docker-entrypoint.sh` や `tools/install-plugin.php` には依存しない。

### 開発環境（自動インストール）

```bash
git clone https://github.com/EcAuth/ec-cube2-ecauth.git
cd ec-cube2-ecauth

# Docker 環境起動。docker-entrypoint.sh が EC-CUBE 本体インストール後に
# プラグインを自動インストール（dtb_plugin INSERT + EcAuthLogin2::install()）する。
docker compose up -d --build
```

`.env` に EcAuth の接続情報を入れておくと、起動時に `dtb_plugin.free_field1` へ自動投入される。

```bash
# .env.tpl を参考に .env を作成
ECAUTH_CLIENT_ID=your_client_id
ECAUTH_CLIENT_SECRET=your_client_secret
ECAUTH_BASE_URL=https://shop1.ec-auth.io
```

## tar.gz アーカイブのビルド

```bash
./tools/build-archive.sh
# → dist/EcAuthLogin2-<version>.tar.gz が生成される
```

EC-CUBE 2 プラグイン仕様書 §3-3 に従い、フォルダごとではなく中身を直接アーカイブする。
開発専用ファイル（`tools/`）は除外される。

## CI / E2E テスト

GitHub Actions の `E2E (Web Install)` ワークフローで、tar.gz を実際に管理画面からアップロードする経路を毎回検証している。

- `.github/workflows/e2e-web-install.yml` — tar.gz ビルド + `SKIP_PLUGIN_INSTALL=true` で素の EC-CUBE を起動 → Playwright で Web インストール → 設定保存までを検証
- `tests/web-install.spec.ts` — 上記フローのテスト本体
- `tests/admin-passkey-flow.spec.ts` — `ECAUTH_E2E_ENABLED=1` 時のみ実行。仮想認証器（CDP `WebAuthn` ドメイン）を使ってパスキー登録〜ログインを検証
- `tests/ecauth-login.spec.ts` — B2C ソーシャルログインフロー

ローカル実行:

```bash
# 1. tar.gz をビルド
./tools/build-archive.sh

# 2. 素の EC-CUBE を起動（プラグイン自動インストールをスキップ）
SKIP_PLUGIN_INSTALL=true docker compose up -d --build

# 3. Playwright 実行
ECCUBE_BASE_URL=https://localhost:24430 npx playwright test tests/web-install.spec.ts
```

## ディレクトリ構成

```
ec-cube2-ecauth/
├── plugin/EcAuthLogin2/                     # ★ プラグイン本体（tar.gz の中身）
│   ├── EcAuthLogin2.php                     # メインクラス（install/uninstall/フック）
│   ├── plugin_info.php                      # プラグイン情報（PLUGIN_CODE 等）
│   ├── data/class/
│   │   ├── helper/SC_Helper_EcAuthLogin2.php           # EcAuth API クライアント + 共通処理
│   │   └── pages/
│   │       ├── ecauth/                                  # フロント側ページクラス
│   │       │   ├── LC_Page_EcAuthLogin2_Authorize.php       # B2C 認可リダイレクト
│   │       │   ├── LC_Page_EcAuthLogin2_Callback.php        # B2B/B2C 共通コールバック
│   │       │   └── LC_Page_EcAuthLogin2_PasskeyApi.php      # パスキー認証 API 中継
│   │       └── admin/ecauth/                            # 管理画面ページクラス
│   │           ├── LC_Page_Admin_EcAuthLogin2_Config.php    # 設定画面
│   │           ├── LC_Page_Admin_EcAuthLogin2_Passkey.php   # パスキー管理画面
│   │           └── LC_Page_Admin_EcAuthLogin2_PasskeyApi.php # パスキー登録 API 中継
│   ├── html/
│   │   ├── ecauth/                                     # 公開 URL（/ecauth/*.php）
│   │   │   ├── authorize.php
│   │   │   ├── callback.php
│   │   │   └── passkey/
│   │   │       ├── authenticate-options.php
│   │   │       └── authenticate-verify.php
│   │   ├── admin/ecauth/                               # 管理画面 URL（/admin/ecauth/*.php）
│   │   │   ├── passkey.php
│   │   │   └── api/{verify-password,register-options,register-verify}.php
│   │   └── plugin/EcAuthLogin2/config.php              # プラグイン管理「設定」リンクのターゲット
│   ├── templates/
│   │   ├── default/plg_EcAuthLogin2_*.tpl              # フロントテンプレート
│   │   └── admin/plg_EcAuthLogin2_admin_*.tpl          # 管理画面テンプレート
│   └── tools/install-plugin.php                        # ★ 開発環境専用 CLI インストーラ
├── tools/build-archive.sh                              # tar.gz アーカイブビルド
├── docker-entrypoint.sh                                # ★ 開発環境専用エントリポイント
├── Dockerfile                                          # plugin/EcAuthLogin2/ を data/downloads/ に配置するのみ
├── docker-compose.yml
├── tests/                                              # Playwright E2E
└── .github/workflows/e2e-web-install.yml               # tar.gz Web インストール経路の CI
```

## DB スキーマ拡張

`install()` の実行時に以下のカラムが追加される。

| テーブル | カラム | 制約 | 用途 |
|---|---|---|---|
| `dtb_member` | `ecauth_subject VARCHAR(255)` | UNIQUE INDEX | B2B パスキーの subject を管理者と紐付け |
| `dtb_customer` | `ecauth_subject VARCHAR(255)` | INDEX | B2C ソーシャルログインの subject を顧客と紐付け |

`uninstall()` ではカラムを保持する（データ保護のため）。

## 設定値

`dtb_plugin.free_field1` に以下の JSON が保存される。

| キー | 必須 | 用途 |
|---|---|---|
| `client_id` | ◯ | EcAuth で発行された Client ID |
| `client_secret` | ◯ | EcAuth で発行された Client Secret |
| `ecauth_base_url` | △ | 未入力時は client_id から ClientResolveService 経由で自動解決 |
| `rp_id` | △ | 未入力時はリクエストホスト名 |
| `provider_name` | △ | B2C ソーシャルログイン時のフェデレーション先（federate-oauth2 等） |

## アーキテクチャ

### B2B パスキーログインフロー

```
[管理画面ログイン画面]
  ↓ 「パスキーでログイン」
[POST /ecauth/passkey/authenticate-options.php]
  → EcAuth /v1/b2b/passkey/authenticate/options (client_id)
  ← {session_id, options}     ← session_id をサーバーセッションに保存
  ↓ navigator.credentials.get()
[POST /ecauth/passkey/authenticate-verify.php]
  → state 生成保存 → EcAuth /v1/b2b/passkey/authenticate/verify
  ← {redirect_url}            ← フロントが location.href = redirect_url
[GET /ecauth/callback.php?code=...&state=...]
  → state 検証（B2B/B2C で session キー切り分け）
  → /v1/token で id_token 取得
  → id_token.sub で dtb_member 検索
  → 管理者セッション確立 → /admin/ にリダイレクト
```

### B2B パスキー登録フロー

```
[パスキー管理画面 「+追加」]
  → パスワード再認証モーダル
[POST /admin/ecauth/api/verify-password.php]
  → SC_Utils_Ex::sfGetHashString で照合
  → ensureB2BUser: dtb_member.ecauth_subject に UUID v4 を発番
  ← {b2b_subject}
[POST /admin/ecauth/api/register-options.php]
  → EcAuth /v1/b2b/passkey/register/options (client_id + secret)
  → reconcileEcauthSubject (EcAuth が解決した subject と Member を同期)
  ← options
  ↓ navigator.credentials.create()
[POST /admin/ecauth/api/register-verify.php]
  → EcAuth /v1/b2b/passkey/register/verify
```

## ライセンス

LGPL-2.1-or-later
