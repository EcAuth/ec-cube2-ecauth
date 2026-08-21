# ec-cube2-ecauth

EC-CUBE 2 系向け EcAuth 認証プラグイン。
B2B パスキー（管理画面ログイン）を提供する。

B2C ソーシャルログイン（OIDC フェデレーション）は実装途中であり、**未提供**。
フロントのログインボタンは設定値 `enable_b2c_login`（既定 `false`）で抑止されている。

## 対応バージョン

- EC-CUBE 2.17, 2.25
- PHP 7.4+ (8.x 動作確認)

> EC-CUBE 2.13 は対象外。ヘルパー各クラスがクラス定数の可視性修飾子
> (`public const` / `private const`、PHP 7.1 で導入) を使っており、
> 2.13 がサポートする PHP 5.3〜5.6 では読み込み時に Parse error になる。
- PostgreSQL / MySQL

## 機能

| 機能 | 対象 | 認証方式 |
|---|---|---|
| 管理画面パスキーログイン | EC-CUBE 管理者 | WebAuthn / FIDO2 + EcAuth /v1/b2b/passkey/* |
| 管理画面パスキー管理 | EC-CUBE 管理者 | パスワード再認証 + パスキー登録 / 削除 |
| 顧客ソーシャルログイン **（未提供）** | EC-CUBE フロント顧客 | OIDC PKCE フロー（Google / LINE 等の外部 IdP）。既定で無効 |

## インストール

### 本番（管理画面 Web インストール）

1. [Releases](../../releases) から `EcAuthLogin2-X.Y.Z.tar.gz` をダウンロード（または自前で `./tools/build-archive.sh` で作成）。
2. EC-CUBE 管理画面 > **オーナーズストア > プラグイン > プラグインを追加する** から tar.gz をアップロードする。
3. プラグインを **有効化** する。
4. プラグイン一覧の **「設定」** リンクから `Client ID` / `Client Secret` を入力して保存。
5. （B2B のみ）管理画面 **基本情報管理 > パスキー管理** から「パスキーを追加」してパスワード再認証 → パスキー登録。
   パスキーは管理者ごとに登録するため、**店舗オーナー権限の管理者も各自で登録する**。
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
ECCUBE_BASE_URL=https://localhost:8081 npx playwright test tests/web-install.spec.ts
```

## ディレクトリ構成

```
ec-cube2-ecauth/
├── plugin/EcAuthLogin2/                     # ★ プラグイン本体（tar.gz の中身）
│   ├── EcAuthLogin2.php                     # メインクラス（install/uninstall/フック）
│   ├── plugin_info.php                      # プラグイン情報（PLUGIN_CODE 等）
│   ├── plugin_update.php                    # アップデート処理（ファイル再配置）
│   ├── config.php                           # プラグイン管理「設定」リンクのターゲット
│   ├── filemap.php                          # ファイル配置表
│   ├── filemap_legacy.php                   # 1.0.4 以前の旧配置（削除対象）の一覧
│   ├── required_files.php                   # 配置せずここから直接読むファイルの検証用一覧
│   ├── data/class/                          # ← EC-CUBE 本体へはコピーせず、ここから直接読む
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
│   │   └── admin/ecauth/                               # 管理画面 URL（/admin/ecauth/*.php）
│   │       ├── passkey.php
│   │       └── api/{verify-password,register-options,register-verify}.php
│   ├── templates/
│   │   └── admin/plg_EcAuthLogin2_admin_*.tpl          # 管理画面テンプレート
│   └── tools/install-plugin.php                        # ★ 開発環境専用 CLI インストーラ
├── tools/build-archive.sh                              # tar.gz アーカイブビルド
├── docker-entrypoint.sh                                # ★ 開発環境専用エントリポイント
├── Dockerfile                                          # plugin/EcAuthLogin2/ を data/downloads/ に配置するのみ
├── docker-compose.yml
├── tests/                                              # Playwright E2E
└── .github/workflows/e2e-web-install.yml               # tar.gz Web インストール経路の CI
```

## インストール時のファイル配置

インストール／アップデート時に EC-CUBE 本体のディレクトリツリーへコピーされるのは、
**Web 公開が必要なエントリポイントとロゴだけ**（配置表は `filemap.php`）。

| プラグイン内パス | 配置先 | 理由 |
|---|---|---|
| `html/ecauth/*.php` | `html/ecauth/` | フロントの公開 URL |
| `html/admin/ecauth/*.php` | `html/<ADMIN_DIR>/ecauth/` | 管理画面の URL。後述のとおり `ADMIN_DIR` 配下である必要がある |
| `logo.png` | `html/plugin/EcAuthLogin2/` | プラグイン管理画面のロゴ（本体仕様の配置先） |

**クラスファイル（`data/class/` 配下）はコピーしない。** `PLUGIN_UPLOAD_REALDIR`
（= `data/downloads/plugin/EcAuthLogin2/`）に置いたまま、各エントリポイントから
直接 `require_once` する。`config.php` と `templates/` も同様に、本体が
`PLUGIN_UPLOAD_REALDIR` から直接読む。

配置表に載らないファイルはインストール時の存在検証も効かないため、`required_files.php`
に一覧を持ち、インストール／アップデートの適用前に検証する。欠けたアーカイブで
「成功」と表示されたまま、実行時に `require_once` で fatal error になるのを防ぐ。

1.0.4 以前は `data/class/` へコピーしていたが、コアのディレクトリツリーを汚染するうえ、
本体が提供する `SC_Plugin_Installer::copyFile()` / `copyDirectory()` のコピー先は
`html/plugin/<plugin_code>/` に固定されており、そもそも本体のインストール API では
実現できない配置だった。1.0.5 でコピーを廃止し、旧バージョンが配置した残骸は
アップデート／アンインストール時に `filemap_legacy.php` を元に削除する。

### 管理画面エントリポイントを `html/plugin/` へ移さない理由

EC-CUBE 2 の管理画面認証は `data/require_base.php` から呼ばれる
`SC_Helper_Session::adminAuthorization()` が担っており、その判定は
**スクリプトの物理パスが `html/<ADMIN_DIR>/` 配下にあるか**で行われる。
`html/plugin/` 配下に置くとこの一括認証を素通りする。

`LC_Page_Admin::init()` には「`ADMIN_DIR` 以外からのリクエストは認証を要求する」
補完的な分岐があるが、これはリリースとしては **EC-CUBE 2.17.2 が初出**で、
2.17.0 / 2.17.1 には存在しない。それらのバージョンでは無認証でアクセス可能な
管理画面 API になってしまうため、管理画面のエントリポイントは
`html/<ADMIN_DIR>/` 配下に配置する。

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
| `enable_b2c_login` | − | フロントに B2C ログインボタンを表示するか。**既定 `false`（未設定時も無効）**。有効値は JSON の `true` のみで、`"true"` のような文字列は無効として扱う |

`enable_b2c_login` は設定画面に入力欄が無い。B2C は未提供であり、管理者に選択肢として
見せる段階にないため。動作確認が必要な場合は `free_field1` の JSON を直接編集する。
`prefilterTransform` は Smarty のテンプレートコンパイル時にしか走らないので、変更後は
コンパイル済みテンプレート（`data/Smarty/templates_c/`）の削除が必要。

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
