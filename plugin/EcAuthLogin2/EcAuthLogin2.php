<?php

/*
 * EcAuthLogin2 プラグインメインクラス
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 設計メモ:
 * - SC_Plugin_Base を継承すると EC-CUBE 2.17.2+ で
 *   `Fatal error: Cannot make static method SC_Plugin_Base::install() non static`
 *   となるため継承しない。回避策としてマジックメソッド経由で
 *   install/uninstall/enable/disable を実装する
 *   (@see https://github.com/EC-CUBE/ec-cube2/issues/551)。
 * - 一方、Web インストール経路（LC_Page_Admin_OwnersStore::execPlugin）は
 *   `method_exists($class_name, $exec_func)` で install 等の存在を確認する。
 *   `method_exists()` は __call/__callStatic のマジックメソッドには
 *   反応しない（PHP 仕様）ため、マジックメソッドだけだと「install が
 *   見つかりません」エラーで Web インストールが失敗する。
 * - 上記を両立するため、**マジックメソッドと実メソッドを併設** する。
 *   実メソッドが優先的に呼ばれ、マジックメソッドは保険として残す。
 */
class EcAuthLogin2
{
    /** @var array プラグイン情報 */
    protected $arrSelfInfo;

    /**
     * ファイル配置表を取得する。
     *
     * 定義の実体は filemap.php にある。static プロパティに直接持たせないのは、
     * アップデート経路（plugin_update.php）が「新バージョンの配置表」を
     * 読めるようにするため。有効なプラグインのクラスファイルは
     * SC_Helper_Plugin::load() がリクエスト冒頭で require_once 済みで、
     * アップデート中に新しい EcAuthLogin2.php を読み直すことはできない。
     *
     * @return array<string,string> [プラグイン内パス => コピー先指定]
     */
    public static function getFileMap()
    {
        return require __DIR__.'/filemap.php';
    }

    public function __construct(array $arrSelfInfo)
    {
        $this->arrSelfInfo = $arrSelfInfo;
    }

    /**
     * EC-CUBE 2 標準のプラグインライフサイクル（Web インストール経路で
     * `method_exists` 経由で検出される）の実メソッド定義。
     * 第二引数 $installer は SC_Plugin_Installer もしくは未指定（CLI 経由）。
     *
     * @param array $plugin dtb_plugin の行
     */
    public static function install($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doInstall($plugin);
    }

    public static function uninstall($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doUninstall($plugin);
    }

    public static function enable($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doEnable($plugin);
    }

    public static function disable($plugin, $installer = null)
    {
        $instance = new self($plugin);
        $instance->doDisable($plugin);
    }

    /**
     * issue #551 回避用のマジックメソッド。
     * SC_Plugin_Base 継承を避けて install/uninstall/enable/disable を実装する
     * 公式回避パターン (@see https://gist.github.com/nanasess/6447bfd9e3cd26815c9ce7d0e8b1cb71)。
     *
     * 上の static 実メソッドが定義されている経路では実メソッドが優先されるため
     * このマジックメソッドは到達しないが、将来 EC-CUBE 本体側のチェック方式が
     * 変更された場合の保険として残す。
     */
    public static function __callStatic($name, $arguments)
    {
        switch ($name) {
            case 'install':
            case 'uninstall':
            case 'enable':
            case 'disable':
                $plugin = isset($arguments[0]) ? $arguments[0] : array();

                return self::$name($plugin, isset($arguments[1]) ? $arguments[1] : null);
        }

        return null;
    }

    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'install':
            case 'uninstall':
            case 'enable':
            case 'disable':
                $plugin = isset($arguments[0]) ? $arguments[0] : $this->arrSelfInfo;

                return self::$name($plugin, isset($arguments[1]) ? $arguments[1] : null);
        }

        return null;
    }

    /**
     * インストール処理。冪等。
     * - dtb_customer / dtb_member に ecauth_subject カラムを追加
     * - dtb_plugin.free_field1 が空なら空 JSON を初期投入
     * - PLUGIN_UPLOAD_REALDIR/EcAuthLogin2/ 配下のファイルを EC-CUBE のディレクトリツリーへコピー
     */
    protected function doInstall($arrPlugin)
    {
        $this->ensureEcAuthSubjectColumn('dtb_customer');
        $this->ensureEcAuthSubjectColumn('dtb_member', true);
        $this->initializeDefaultConfig();
        $this->copyPluginFiles();
    }

    /**
     * アンインストール処理。
     * - 配置したファイルを削除する
     * - DB のカラムは残す（データ保持のため）
     */
    protected function doUninstall($arrPlugin)
    {
        $this->removePluginFiles();
    }

    protected function doEnable($arrPlugin)
    {
        // 必要時のみキャッシュクリア等
    }

    protected function doDisable($arrPlugin)
    {
        // 必要時のみキャッシュクリア等
    }

    /**
     * 処理の介入箇所とコールバック関数を設定
     *
     * @param int $priority
     */
    public function register(SC_Helper_Plugin $objHelperPlugin, $priority)
    {
        if (!isset($this->arrSelfInfo['plugin_hook_point'])) {
            return;
        }
        foreach ($this->arrSelfInfo['plugin_hook_point'] as $hookPoint) {
            if (!isset($hookPoint['callback'])) {
                continue;
            }
            $objHelperPlugin->addAction(
                $hookPoint['hook_point'],
                array($this, $hookPoint['callback']),
                $priority
            );
        }
    }

    public function getPluginInfo()
    {
        return $this->arrSelfInfo;
    }

    // ========================================================================
    // フックポイント
    // ========================================================================
    /**
     * Smarty テンプレートのプレフィルタ。
     * - フロントの mypage/login.tpl と shopping/index.tpl に B2C ログインボタンを差し込む
     *   （既定では enable_b2c_login が false のため差し込まれない）
     * - 管理画面の admin/login.tpl にパスキーログインスクリプトを差し込む（Phase B-3 で実装）
     * - 管理画面の basis/subnavi.tpl に「パスキー管理」メニューを差し込む
     *
     * @param string $source テンプレートソース
     * @param string $filename
     */
    public function prefilterTransform(&$source, LC_Page_Ex $objPage, $filename)
    {
        // B2C ログインボタンは設定値 enable_b2c_login（既定 false）で抑止される。
        // @see insertB2CLoginButton()
        if ($filename === 'mypage/login.tpl' || $filename === 'shopping/index.tpl') {
            $this->insertB2CLoginButton($source, $filename);

            return;
        }

        // EC-CUBE 2 の admin ログイン画面のテンプレートファイル名は "login.tpl"
        // (admin/ プレフィックスは付かない)。フロントの mypage/login.tpl とは
        // 上のブロックで分岐済みなので、ここに来た login.tpl は admin と扱う。
        if ($filename === 'login.tpl') {
            $this->insertAdminPasskeyScript($source);

            return;
        }

        if ($filename === 'basis/subnavi.tpl') {
            $this->insertBasisSubnaviMenu($source);

            return;
        }
    }

    /**
     * 「基本情報管理」のサブナビへ「パスキー管理」を追加する。
     *
     * パスキー管理画面（admin/ecauth/passkey.php）への導線が、これまでプラグイン設定画面の
     * ボタンしか無かった。設定画面は「オーナーズストア」配下にあり、本体の
     * html/user_data/packages/admin/css/admin.css が `.authority_1` で #navi-ownersstore ごと
     * 非表示にするため、店舗オーナー権限の管理者は自分のパスキーを登録できなかった。
     *
     * パスキーはログイン中の管理者ごとに登録するもの（verify-password → ensureB2BUser が
     * dtb_member 単位で subject を発行する）なので、全権限から見える基本情報管理に置く。
     * なお mtb_permission に /ecauth/passkey.php の登録は無く、SC_Session::IsSuccess() は
     * 未登録パスを素通しするため、アクセス制御上は元から店舗オーナーも利用できる。
     */
    protected function insertBasisSubnaviMenu(&$source)
    {
        $tplFile = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_basis_subnavi.tpl';
        if (!is_file($tplFile)) {
            return;
        }

        $snippet = file_get_contents($tplFile);
        if ($snippet === false || trim($snippet) === '') {
            return;
        }

        // 挿入は SC_Helper_Transform を使う（プラグイン開発マニュアル 3-11 / 4-1 の推奨手法）。
        // 文字列置換と違いセレクタ指定なので、本体の subnavi.tpl の項目が増減しても壊れない。
        // Transform は挿入する断片をプレースホルダに退避して getHTML() で復元するため、
        // 断片内の Smarty タグ（属性位置の <!--{if}--> を含む）はパースされず安全。
        //
        // select() の第 3 引数 $require に false を渡すのが重要。既定の true のままだと
        // セレクタ不一致が致命的エラーとして積まれ、getHTML() が SC_Utils_Ex::sfDispSiteError()
        // を呼ぶため、メニュー 1 項目のせいで基本情報管理の画面全体がエラー表示になる。
        // false ならセレクタが無いとき appendChild が何もせず、getHTML() は元ソースを返す。
        $objTransform = new SC_Helper_Transform($source);
        $objTransform->select('ul.level1', null, false)->appendChild($snippet);
        $transformed = $objTransform->getHTML();

        // 念のため。null が返るのはエラー経路のみだが、その場合も元ソースを維持する
        // （導線はプラグイン設定画面側に残っているので、メニューが出ないだけで済む）。
        if (!is_string($transformed) || $transformed === '') {
            return;
        }

        $source = $transformed;
    }

    /**
     * B2C ログインボタンを挿入する。
     *
     * 注意: B2C OIDC フェデレーションは後続リリースで正式提供予定であり、本機能は
     * 現段階では実運用での使用を想定していない。表示は設定値 `enable_b2c_login`
     * （既定 false）で抑止する。
     *
     * かつては「`client_id` を保存しない運用にすればボタンは出ない」という前提で
     * 条件が `client_id` の有無だけになっていたが、B2B パスキーが同じ `client_id` を
     * 使う（かつ設定画面で必須入力の）ため、この前提は成立しない。結果として B2B
     * だけを使う構成でもフロントに未提供機能への導線が露出していた (#29)。
     *
     * `enable_b2c_login` は設定画面に入力欄を設けていない。B2C は未提供であり、
     * 管理者に選択肢として見せる段階にないため。動作確認が必要な場合は
     * dtb_plugin.free_field1 の JSON を直接編集して有効化する。
     */
    protected function insertB2CLoginButton(&$source, $filename)
    {
        $config = $this->loadConfig();
        if (empty($config['client_id'])) {
            return;
        }

        // 既存インストールでは当該キー自体が存在しないため、未設定は無効として扱う
        // （設定のマイグレーションは不要）。
        //
        // 有効値は boolean の true のみに限定する。この設定は画面から入力せず
        // free_field1 の JSON を直接編集して与えるため、empty() 判定だと
        // {"enable_b2c_login":"false"} のような文字列を「有効」と解釈してしまう。
        // 未提供機能のゲートなので、曖昧な値はすべて無効側に倒す。
        if (!isset($config['enable_b2c_login']) || $config['enable_b2c_login'] !== true) {
            return;
        }

        $providerName = empty($config['provider_name']) ? 'EcAuth' : $config['provider_name'];
        $authorizeUrl = HTTPS_URL . 'ecauth/authorize.php';

        $providerNameHtml = htmlspecialchars($providerName, ENT_QUOTES, 'UTF-8');
        $button = '<div class="ecauth-login-button" style="margin-top: 15px; text-align: center;">'
            . '<a href="' . htmlspecialchars($authorizeUrl, ENT_QUOTES, 'UTF-8') . '"'
            . ' class="btn btn-primary" style="background-color: #4285f4; border-color: #4285f4; color: #fff; padding: 10px 20px; text-decoration: none; display: inline-block; border-radius: 4px;">'
            . $providerNameHtml . ' でログイン'
            . '</a></div>';

        $pattern = '/(<input[^>]*alt=["\']ログイン["\'][^>]*\/>)/iu';
        if (preg_match($pattern, $source)) {
            $source = preg_replace($pattern, '$1' . "\n" . $button, $source, 1);

            return;
        }

        // </ul> の直前に <li> を挿入する。$1（btn_area の中身）と $2（</ul>）を分離キャプチャして
        // <li> をリスト内側に配置することで、無効な HTML（<ul>...</ul><li>...</li>）になるのを防ぐ。
        // $button にメタ文字（$0 等）が含まれた場合の preg_replace 衝突も avoid するため callback 形式。
        $fallbackPattern = '/(<div class="btn_area">.*?)(<\/ul>)/is';
        if (preg_match($fallbackPattern, $source)) {
            $source = preg_replace_callback(
                $fallbackPattern,
                function ($matches) use ($button) {
                    return $matches[1] . "\n" . '<li>' . $button . '</li>' . $matches[2];
                },
                $source,
                1
            );
        }
    }

    /**
     * 管理画面ログイン画面にパスキーログインスクリプトを挿入する。
     * 実装は Phase B-3。ここではテンプレートが存在すれば読み込んで </body> 直前に挿入するだけ。
     */
    protected function insertAdminPasskeyScript(&$source)
    {
        $tplFile = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_login_passkey.tpl';
        if (!is_file($tplFile)) {
            return;
        }

        // client_id 未設定時もスクリプトは常に注入する。
        // Smarty は prefilterTransform をテンプレートコンパイル時にしか実行しないため、
        // 「設定保存前にコンパイル → 後から設定保存」の場合キャッシュが効いて
        // 再コンパイルされず、条件付き注入だと永続的にボタンが出なくなる。
        // 設定未保存時はクリック後の API fetch が失敗してアラート表示する仕掛けで握り潰す。
        $script = file_get_contents($tplFile);
        if ($script === false || $script === '') {
            return;
        }

        // テンプレートは file_get_contents で読み込まれるため Smarty 経路を通らない。
        // {$smarty.const.HTTPS_URL} の展開も効かないので、サブディレクトリインストール
        // (ROOT_URLPATH=/shop/ 等) でも正しく解決される絶対 URL をプレースホルダ置換で埋め込む。
        $httpsUrl = rtrim(HTTPS_URL, '/');
        $script = str_replace(
            array('%%ECAUTH_OPTIONS_URL%%', '%%ECAUTH_VERIFY_URL%%'),
            array(
                $httpsUrl . '/ecauth/passkey/authenticate-options.php',
                $httpsUrl . '/ecauth/passkey/authenticate-verify.php',
            ),
            $script
        );

        // </body> 直前に挿入（無い場合は末尾追加）
        // 置換は callback 形式にする。$script は JS なので `$0` `$1` のような
        // メタ文字を含みうるが、preg_replace の置換文字列ではそれが後方参照として
        // 展開され、スクリプトが静かに壊れる（`$0` がマッチした </body> に化ける）。
        // @see insertB2CLoginButton() の同じ対処
        if (stripos($source, '</body>') !== false) {
            $source = preg_replace_callback(
                '/<\/body>/i',
                function ($matches) use ($script) {
                    return $script . "\n" . $matches[0];
                },
                $source,
                1
            );

            return;
        }
        $source .= $script;
    }

    // ========================================================================
    // Internal: install ヘルパー
    // ========================================================================

    /**
     * 列とインデックスを冪等に確保する。
     * 列だけ存在しインデックスが欠損している環境（途中失敗・手動追加・
     * 旧バージョンからのアップグレード等）でもインデックスを補修する。
     *
     * @param string $table テーブル名
     * @param bool $unique UNIQUE 制約を付けるか（dtb_member は UNIQUE 必須）
     */
    protected function ensureEcAuthSubjectColumn($table, $unique = false)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();

        // (1) 列の確保
        $columns = $objQuery->listTableFields($table);
        if (!in_array('ecauth_subject', $columns)) {
            $objQuery->query('ALTER TABLE ' . $table . ' ADD ecauth_subject VARCHAR(255)');
            error_log('[EcAuthLogin2] Added ecauth_subject column to ' . $table);
        }

        // (2) インデックスの確保（列が既存でもインデックスだけ無い状態を補修）
        $indexName = 'idx_' . $table . '_ecauth_subject';
        if (!$this->indexExists($table, $indexName)) {
            if ($unique) {
                $objQuery->query('CREATE UNIQUE INDEX ' . $indexName . ' ON ' . $table . '(ecauth_subject)');
            } else {
                $objQuery->query('CREATE INDEX ' . $indexName . ' ON ' . $table . '(ecauth_subject)');
            }
            error_log('[EcAuthLogin2] Created index ' . $indexName . ' on ' . $table);
        }
    }

    /**
     * インデックス存在チェック（PostgreSQL / MySQL 両対応）。
     * 確認失敗時は false を返し、後続の CREATE INDEX で例外を再表面化させる。
     *
     * @param string $table
     * @param string $indexName
     * @return bool
     */
    protected function indexExists($table, $indexName)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        try {
            if (defined('DB_TYPE') && DB_TYPE === 'pgsql') {
                $row = $objQuery->getRow(
                    'indexname',
                    'pg_indexes',
                    'tablename = ? AND indexname = ?',
                    array($table, $indexName)
                );
            } else {
                $row = $objQuery->getRow(
                    'INDEX_NAME',
                    'INFORMATION_SCHEMA.STATISTICS',
                    'TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    array($table, $indexName)
                );
            }
        } catch (Exception $e) {
            return false;
        }

        return !empty($row);
    }

    protected function initializeDefaultConfig()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $row = $objQuery->getRow('free_field1', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));
        if (!empty($row['free_field1'])) {
            return;
        }
        $objQuery->update(
            'dtb_plugin',
            array(
                'free_field1' => json_encode(new stdClass(), JSON_UNESCAPED_UNICODE),
                'update_date' => 'CURRENT_TIMESTAMP',
            ),
            'plugin_code = ?',
            array('EcAuthLogin2')
        );
    }

    /**
     * fileMap に従いファイルを配置する。
     *
     * 重要: ソース未検出・mkdir 失敗・copy 失敗はいずれも RuntimeException を
     * 送出し、install を失敗扱いにする。`error_log` だけで握りつぶすと
     * 「インストール成功表示だが実行時にクラス未発見/404」というワースト
     * ケースになるため、明示的に abort する。
     *
     * @throws RuntimeException
     */
    protected function copyPluginFiles()
    {
        $base = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/';

        foreach (self::getFileMap() as $relativeSrc => $destSpec) {
            $src = $base . $relativeSrc;
            $dest = $this->expandDestSpec($destSpec);
            if (!is_file($src)) {
                $message = '[EcAuthLogin2] Source file missing: ' . $src;
                error_log($message);
                throw new RuntimeException($message);
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                $message = '[EcAuthLogin2] mkdir failed: ' . $destDir;
                error_log($message);
                throw new RuntimeException($message);
            }
            if (!copy($src, $dest)) {
                $message = '[EcAuthLogin2] Copy failed: ' . $src . ' -> ' . $dest;
                error_log($message);
                throw new RuntimeException($message);
            }
        }
    }

    protected function removePluginFiles()
    {
        foreach (self::getFileMap() as $destSpec) {
            $dest = $this->expandDestSpec($destSpec);
            if (is_file($dest)) {
                @unlink($dest);
            }
        }
        $this->cleanupEmptyDir(CLASS_REALDIR . 'pages/ecauth');
        $this->cleanupEmptyDir(CLASS_REALDIR . 'pages/admin/ecauth');
        $this->cleanupEmptyDir(HTML_REALDIR . 'ecauth/passkey');
        $this->cleanupEmptyDir(HTML_REALDIR . 'ecauth');
        if (defined('ADMIN_DIR')) {
            $this->cleanupEmptyDir(HTML_REALDIR . ADMIN_DIR . 'ecauth/api');
            $this->cleanupEmptyDir(HTML_REALDIR . ADMIN_DIR . 'ecauth');
        }
    }

    protected function cleanupEmptyDir($dir)
    {
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
        }
    }

    /**
     * "PLACEHOLDER:relative/path" 形式を絶対パスに展開する。
     */
    protected function expandDestSpec($destSpec)
    {
        list($placeholder, $relative) = explode(':', $destSpec, 2);
        switch ($placeholder) {
            case 'CLASS_REALDIR':
                return CLASS_REALDIR . $relative;
            case 'HTML_REALDIR':
                return HTML_REALDIR . $relative;
            case 'ADMIN_HTML_REALDIR':
                return HTML_REALDIR . ADMIN_DIR . $relative;
            case 'PLUGIN_HTML_REALDIR':
                return PLUGIN_HTML_REALDIR . $relative;
            default:
                return $destSpec;
        }
    }

    protected function loadConfig()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $row = $objQuery->getRow('free_field1', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));
        if (empty($row['free_field1'])) {
            return array();
        }
        $config = json_decode($row['free_field1'], true);

        return is_array($config) ? $config : array();
    }
}
