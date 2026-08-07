# CLAUDE.md

このファイルは Claude Code (claude.ai/code) がこのリポジトリで作業する際のガイダンスを提供します。
インストール手順・機能一覧・CI の構成は [README.md](./README.md) を参照してください。ここには
**ソースを読んでも分かりにくい / 毎回再導出する羽目になる事実**を書きます。

## プロジェクト概要

EC-CUBE 2 系（2.17 / 2.25）向け EcAuth 認証プラグイン `EcAuthLogin2`。
管理画面の B2B パスキーログインと、フロントの B2C ソーシャルログイン（OIDC）を提供する。

## 参考資料

**[EC-CUBE プラグイン開発マニュアル (12.0)](https://downloads.ec-cube.net/manual/12.0_plugin/plugin.pdf)**
（PDF・全 41 ページ）が一次情報。プラグインの構成やフックの作法で迷ったらまずこれを参照する。

読むときの要点:

| 章 | 内容 |
|---|---|
| 3-2 プラグインファイル構成 | `config.php` / `logo.png` の役割と配置先 |
| 3-3 アーカイブ作成方法 | tar.gz は**フォルダごとではなく中身**を固める |
| 3-7 / 3-8 フックポイント | スーパーフック / ローカルフック |
| 3-11 テンプレートの変更 | `prefilterTransform` / `outputfilterTransform` |
| 4-1 SC_Helper_Transform | `select` / `insertBefore` / `appendChild` 等のリファレンス |

**注意**: マニュアルに**管理画面メニューの追加方法は記載がない**。3-12 に出てくる「ナビに追加」は
`SC_Helper_Plugin::setHeadNavi`（`<head>` へのタグ追加）の話でメニューとは無関係。メニュー追加は
3-11 / 4-1 のテンプレート改変で行うしかない（後述）。

PDF はテキスト抽出して読むのが早い。

```bash
curl -sL -o /tmp/plugin.pdf https://downloads.ec-cube.net/manual/12.0_plugin/plugin.pdf
pdftotext -layout /tmp/plugin.pdf /tmp/plugin.txt
```

## 開発コマンド

```bash
docker compose up -d --build     # 起動（docker-entrypoint.sh がプラグインを自動インストール）
docker compose logs ec-cube      # ログ
docker compose down -v           # 破棄（-v でボリュームごと）

composer cs-check                # PHP CS Fixer (dry-run) / cs-fix で修正
composer phpstan                 # PHPStan
composer rector                  # Rector (dry-run) / rector:fix で修正
composer phpunit                 # PHPUnit

./tools/build-archive.sh         # dist/EcAuthLogin2-<version>.tar.gz を生成
```

### ポート競合に注意

`docker-compose.yml` は **443 を `8081` に publish** する。EcAuth 本体のローカル Docker 環境
（`docker compose -p ec-auth`）の `identityprovider` も 8081 を使うため、**両方を同時に起動できない**。

```bash
docker compose -p ec-auth down   # EcAuth 側を止めてから起動する
```

`HTTP_URL` / `HTTPS_URL` が `https://localhost:8081/` 前提なので、ポートだけ変えると
リダイレクトと `redirect_uri` が壊れる。別ポートで動かす場合は環境変数も併せて上書きすること。

### 構文チェックはホスト側の php で行う

`docker compose exec ec-cube php -l <path>` はコンテナ内の**ビルド時にコピーされた古いソース**を
見る（`Dockerfile` の `COPY plugin/EcAuthLogin2 …`）。ワーキングツリーを検査したいときは
ホストの php を使う。

```bash
php -l plugin/EcAuthLogin2/EcAuthLogin2.php
```

## ファイル配置の仕組み

EC-CUBE 2 のプラグインは、展開先（`data/downloads/plugin/EcAuthLogin2/`）から本体各所へ
**自前でファイルをコピーする**。その対応表が `filemap.php`。

```php
'logo.png' => 'PLUGIN_HTML_REALDIR:EcAuthLogin2/logo.png',
```

- プレースホルダは `EcAuthLogin2::expandDestSpec()` が解決する（`CLASS_REALDIR` /
  `HTML_REALDIR` / `ADMIN_HTML_REALDIR` / `PLUGIN_HTML_REALDIR`）
- **`PLUGIN_HTML_REALDIR` は `html/plugin/` までしか指さない**。プラグインコードのディレクトリは
  自分で付ける
- コピー先の親ディレクトリは `copyPluginFiles()` が `mkdir -p` 相当で作る
- `templates/` と `config.php` は `PLUGIN_UPLOAD_REALDIR` から直接読まれるため配置表に載せない

### ファイルを追加したら PLUGIN_VERSION を上げる

`plugin_update.php`（管理画面の「アップデート」）は**新バージョンの `filemap.php` を直接
require** して配置する。`plugin_info.php` の `$PLUGIN_VERSION` を上げないとアップデート自体が
走らず、**既存環境に新規ファイルが配置されない**。`@version` の docblock も併せて更新する。

なお `plugin_update::update()` は配置後に `SC_Utils_Ex::clearCompliedTemplate()` を呼ぶので、
テンプレート改変（後述）はアップデート経路でも反映される。

### logo.png

プラグイン管理画面に表示されるロゴ。**縦 65 × 横 65 ピクセル**（マニュアル 3-2）。
`LC_Page_Admin_OwnersStore.php` が `PLUGIN_HTML_REALDIR.<plugin_code>/logo.png` の存在を見て、
無ければ `noimage_plugin_list.png` にフォールバックする。画像は EcAuthDocs のブランド資産
（`html/assets/logo/ecauth-icon-50.svg`）から書き出す。

```bash
rsvg-convert -w 65 -h 65 -o plugin/EcAuthLogin2/logo.png <EcAuthDocs>/html/assets/logo/ecauth-icon-50.svg
```

## 管理画面まわりの実装知識

### 権限は mtb_authority / mtb_permission で決まるが、メニューは CSS で隠している

`mtb_authority` は `0` = システム管理者 / `1` = 店舗オーナーの 2 値。アクセス制御は
`SC_Session::IsSuccess()`（`data/class/SC_Session.php`）が行う。

```php
foreach ($arrPERMISSION as $path => $auth) {
    // 実行中スクリプトと path が一致した場合のみ
    if ($auth < $this->authority) { return ACCESS_ERROR; }
}
return SUCCESS;   // ← 一致しなければ無条件で通過
```

**`mtb_permission` に登録が無いパスは全 authority が通過する。** 既定の登録は 12 件だけで、
`/system/*` が `0`、`/entry/*` 等が `1`。`/ownersstore/*` も本プラグインの `/ecauth/*` も未登録
なので、**アクセス制御上は店舗オーナーも到達できる**。

一方、メニューの出し分けは**本体 CSS** が行う。`main_frame.tpl` が
`<body class="authority_{$tpl_authority}">` を出力し、
`html/user_data/packages/admin/css/admin.css` の `.authority_1` が
`#navi-system` と `#navi-ownersstore` を含む項目を `visibility: hidden` にする。

> この非対称性が実際に不具合を生んだ。パスキー管理画面への導線がプラグイン設定画面
> （オーナーズストア配下）にしか無く、店舗オーナーからは**メニューごと見えない**ため、
> 権限はあるのに登録できなかった。全権限に見せたい画面は「基本情報管理」など隠されない
> メニューに置く（修正: PR #20）。

### メニューの追加は SC_Helper_Transform で行う

`prefilterTransform` で対象テンプレートを判定し、`SC_Helper_Transform` で挿入する
（実装例は `EcAuthLogin2::insertBasisSubnaviMenu()`）。

```php
$objTransform = new SC_Helper_Transform($source);
$objTransform->select('ul.level1', null, false)->appendChild($snippet);
$source = $objTransform->getHTML();
```

踏みやすい罠が 2 つある。

1. **`select()` の第 3 引数 `$require` は必ず `false` にする。** 既定の `true` だとセレクタ
   不一致が致命的エラーとして積まれ、`getHTML()` が `SC_Utils_Ex::sfDispSiteError()` を呼ぶ。
   メニュー 1 項目のために**画面全体がエラー表示になる**。`false` なら `appendChild` が何もせず、
   `getHTML()` は元ソースをそのまま返す
2. `getHTML()` はエラー時に `null` を返す。戻り値は `is_string()` で検査してから `$source` に代入する

挿入する断片に Smarty タグを含めてよい。`lfSetTransform()` が断片をプレースホルダ
（`<!--###00000000###-->`）に退避し、`getHTML()` で復元するため、**断片はパースされない**。
属性位置の `<!--{if $tpl_subno == '...'}--> class="on"<!--{/if}-->` も安全に書ける。

セレクタは**タグ名・ID 名・クラス名のみ**対応（マニュアル 4-1）。

### prefilter はコンパイル時にしか走らない

`prefilterTransform` は Smarty のコンパイル時のみ実行される。`templates_c/` にコンパイル済み
ファイルがあると走らないため、テンプレートだけ差し替えたときはキャッシュ削除が要る。
インストール／アップデート経路では本体・`plugin_update.php` が破棄するので通常は意識不要。

### ページクラスの tpl_mainno はメニューのハイライト先

`LC_Page_Admin_*::init()` の `tpl_mainno` / `tpl_subno` が、どのメニューを `on` にするかを決める。
**メニューを移したら `tpl_mainno` も合わせる。** ずれたままだと、店舗オーナーが開いたときに
ハイライト先が CSS で隠された要素になり表示が崩れる。

## EcAuth 連携で踏みやすい罠

### ecauth_subject は client_id を変えても再利用される

`SC_Helper_EcAuthLogin2::ensureB2BUser()` は `b2b_subject` を `dtb_member.ecauth_subject` に
永続化し、値があれば**無条件に再利用**する。一方 EcAuth 側の `B2BUser.Subject` はグローバル一意
なので、**テスト用テナントの `client_id` で試した後に本番用へ差し替えると、別 Organization に
同じ subject を登録しようとして必ず失敗する**（`register/options` が 400）。

EcAuth 側のログに `Failed to create or retrieve B2BUser: <uuid>` が出ていればこれ。
回避は対象管理者の `ecauth_subject` をクリアすること。

```sql
UPDATE dtb_member SET ecauth_subject = NULL, update_date = CURRENT_TIMESTAMP WHERE member_id = ?;
```

恒久対応は [EcAuth/ec-cube2-ecauth#18](https://github.com/EcAuth/ec-cube2-ecauth/issues/18)。

### EcAuth API の日時は ISO 8601 (UTC)

`created_at` / `last_used_at` は `2026-08-07T09:53:16+00:00` 形式で返る。表示するときは
ローカル時刻へ変換する（`LC_Page_Admin_EcAuthLogin2_Passkey::formatDateTime()`）。タイムゾーンは
`Asia/Tokyo` を直書きせず `date_default_timezone_get()` に従う。本体が `SC_Initial` で
`date_default_timezone_set('Asia/Tokyo')` を実行済みのため、本体設定に追従できる。

### プラグインの接続先は client_id から解決される

プラグインは `/platform/v1/client-resolve` に `client_id` を投げ、返ってきた `base_url`
（`https://{tenant}.ec-auth.io`）を設定値として保存し、以降の API 呼び出しをそこへ送る。
`SC_Helper_EcAuthLogin2::CLIENT_RESOLVE_PATH` / `DEFAULT_DISCOVERY_URL` を参照。
`client-resolve` の応答は無条件に信頼せず `SC_Helper_EcAuthLogin2_BaseUrl` で検証している
（応答が汚染されるとトークン交換先ごと攻撃者のホストに向くため）。

## コーディング規約

- 行末の空白は削除、改行コードは LF
- PHP 7.4 で動くこと（`public const` は可、アロー関数や `match` は不可）
- コミット前に `composer cs-check` と `composer phpstan` を通す
- コミットメッセージは Conventional Commits + 日本語本文
- 本体のコアファイルを書き換えない。プラグインは**管理画面の Web インストーラーから導入できる**
  ことが絶対条件で、コア改変や docker 側の後付け修正に依存してはいけない
