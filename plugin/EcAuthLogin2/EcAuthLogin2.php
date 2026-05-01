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
 * SC_Plugin_Base を継承すると EC-CUBE 2.17+ でエラーになるため
 * マジックメソッドで install/uninstall/enable/disable を実装する。
 *
 * @see https://github.com/EC-CUBE/ec-cube2/issues/551
 */
class EcAuthLogin2
{
    /** @var array プラグイン情報 */
    protected $arrSelfInfo;

    /** @var array<string,string> [プラグイン内パス => コピー先絶対パス] */
    protected static $fileMap = array(
        // 共通ヘルパー
        'data/class/helper/SC_Helper_EcAuthLogin2.php'
            => 'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2.php',

        // B2C ソーシャルログイン用ページクラス
        'data/class/pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php'
            => 'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php',
        'data/class/pages/ecauth/LC_Page_EcAuthLogin2_Callback.php'
            => 'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_Callback.php',

        // B2B 管理画面ページクラス
        'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php'
            => 'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php',
        'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php'
            => 'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php',
        'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php'
            => 'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php',
        'data/class/pages/ecauth/LC_Page_EcAuthLogin2_PasskeyApi.php'
            => 'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_PasskeyApi.php',

        // B2C ソーシャルログイン用エントリポイント
        'html/ecauth/authorize.php' => 'HTML_REALDIR:ecauth/authorize.php',
        'html/ecauth/callback.php' => 'HTML_REALDIR:ecauth/callback.php',

        // B2B パスキー認証 API（フロント側、認証不要）
        'html/ecauth/passkey/authenticate-options.php'
            => 'HTML_REALDIR:ecauth/passkey/authenticate-options.php',
        'html/ecauth/passkey/authenticate-verify.php'
            => 'HTML_REALDIR:ecauth/passkey/authenticate-verify.php',

        // B2B パスキー登録 API（管理画面、管理者認証必須）
        'html/admin/ecauth/passkey.php' => 'ADMIN_HTML_REALDIR:ecauth/passkey.php',
        'html/admin/ecauth/api/verify-password.php'
            => 'ADMIN_HTML_REALDIR:ecauth/api/verify-password.php',
        'html/admin/ecauth/api/register-options.php'
            => 'ADMIN_HTML_REALDIR:ecauth/api/register-options.php',
        'html/admin/ecauth/api/register-verify.php'
            => 'ADMIN_HTML_REALDIR:ecauth/api/register-verify.php',

        // 管理画面プラグイン管理「設定」リンクは
        // PLUGIN_UPLOAD_REALDIR/<PLUGIN_CODE>/config.php （= プラグインルートの config.php）
        // を直接 require_once する仕様のため、ファイルコピーは不要。
        // tar.gz に config.php が含まれていれば設定リンクが自動的に有効になる。
    );

    public function __construct(array $arrSelfInfo)
    {
        $this->arrSelfInfo = $arrSelfInfo;
    }

    public static function __callStatic($name, $arguments)
    {
        switch ($name) {
            case 'install':
            case 'uninstall':
            case 'enable':
            case 'disable':
                $instance = new self($arguments[0]);

                return call_user_func(array($instance, 'do' . ucfirst($name)), $arguments[0]);
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
                return call_user_func(array($this, 'do' . ucfirst($name)), $arguments[0]);
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
     * - 管理画面の admin/login.tpl にパスキーログインスクリプトを差し込む（Phase B-3 で実装）
     *
     * @param string $source テンプレートソース
     * @param string $filename
     */
    public function prefilterTransform(&$source, LC_Page_Ex $objPage, $filename)
    {
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
    }

    /**
     * B2C ログインボタンを挿入する（既存ロジック）
     *
     * 注意: B2C OIDC フェデレーションは後続リリースで正式提供予定であり、本機能は
     * 現段階では実運用での使用を想定していない。`client_id` が dtb_plugin に
     * 保存されていれば mypage/login.tpl 等にボタンが描画されるが、現フェーズでは
     * 設定保存しない運用とすることでボタンを表示させない (動作確認用にコードは残す)。
     */
    protected function insertB2CLoginButton(&$source, $filename)
    {
        $config = $this->loadConfig();
        if (empty($config['client_id'])) {
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

        $fallbackPattern = '/(<div class="btn_area">.*?<\/ul>)/is';
        if (preg_match($fallbackPattern, $source)) {
            $source = preg_replace($fallbackPattern, '$1' . "\n" . '<li>' . $button . '</li>', $source, 1);
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
        if (stripos($source, '</body>') !== false) {
            $source = preg_replace('/<\/body>/i', $script . "\n</body>", $source, 1);

            return;
        }
        $source .= $script;
    }

    // ========================================================================
    // Internal: install ヘルパー
    // ========================================================================

    /**
     * @param string $table テーブル名
     * @param bool $unique UNIQUE 制約を付けるか（dtb_member は UNIQUE 必須）
     */
    protected function ensureEcAuthSubjectColumn($table, $unique = false)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $columns = $objQuery->listTableFields($table);
        if (in_array('ecauth_subject', $columns)) {
            return;
        }

        $objQuery->query('ALTER TABLE ' . $table . ' ADD ecauth_subject VARCHAR(255)');

        $indexName = 'idx_' . $table . '_ecauth_subject';
        if ($unique) {
            $objQuery->query('CREATE UNIQUE INDEX ' . $indexName . ' ON ' . $table . '(ecauth_subject)');
        } else {
            $objQuery->query('CREATE INDEX ' . $indexName . ' ON ' . $table . '(ecauth_subject)');
        }

        error_log('[EcAuthLogin2] Added ecauth_subject column to ' . $table);
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

    protected function copyPluginFiles()
    {
        $base = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/';

        foreach (self::$fileMap as $relativeSrc => $destSpec) {
            $src = $base . $relativeSrc;
            $dest = $this->expandDestSpec($destSpec);
            if (!is_file($src)) {
                error_log('[EcAuthLogin2] Source not found: ' . $src);
                continue;
            }
            $destDir = dirname($dest);
            if (!is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }
            if (!copy($src, $dest)) {
                error_log('[EcAuthLogin2] Copy failed: ' . $src . ' -> ' . $dest);
            }
        }
    }

    protected function removePluginFiles()
    {
        foreach (self::$fileMap as $destSpec) {
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
