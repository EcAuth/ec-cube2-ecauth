<?php
/**
 * EcAuthLogin2 プラグインメインクラス
 *
 * SC_Plugin_Base を継承すると EC-CUBE 2.17+ でエラーになるため
 * マジックメソッドを使用して実装
 * @see https://github.com/EC-CUBE/ec-cube2/issues/551
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */
class EcAuthLogin2
{
    /** @var array プラグイン情報 */
    protected $arrSelfInfo;

    /**
     * コンストラクタ
     *
     * @param array $arrSelfInfo プラグイン情報
     */
    public function __construct(array $arrSelfInfo)
    {
        $this->arrSelfInfo = $arrSelfInfo;
    }

    /**
     * 静的メソッド呼び出しのマジックメソッド
     *
     * @param string $name メソッド名
     * @param array $arguments 引数
     * @return mixed
     */
    public static function __callStatic($name, $arguments)
    {
        switch ($name) {
            case 'install':
                // 静的呼び出しの場合
                $instance = new self($arguments[0]);
                return $instance->doInstall($arguments[0]);
            case 'uninstall':
                $instance = new self($arguments[0]);
                return $instance->doUninstall($arguments[0]);
            case 'enable':
                $instance = new self($arguments[0]);
                return $instance->doEnable($arguments[0]);
            case 'disable':
                $instance = new self($arguments[0]);
                return $instance->doDisable($arguments[0]);
        }
    }

    /**
     * インスタンスメソッド呼び出しのマジックメソッド
     *
     * @param string $name メソッド名
     * @param array $arguments 引数
     * @return mixed
     */
    public function __call($name, $arguments)
    {
        switch ($name) {
            case 'install':
                return $this->doInstall($arguments[0]);
            case 'uninstall':
                return $this->doUninstall($arguments[0]);
            case 'enable':
                return $this->doEnable($arguments[0]);
            case 'disable':
                return $this->doDisable($arguments[0]);
        }
    }

    /**
     * インストール処理
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    protected function doInstall($arrPlugin)
    {
        // dtb_customer に ecauth_subject カラムを追加
        $this->addEcAuthSubjectColumn();

        // 必要なファイルをコピー
        $this->copyFiles();
    }

    /**
     * アンインストール処理
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    protected function doUninstall($arrPlugin)
    {
        // コピーしたファイルを削除
        $this->removeFiles();

        // カラムは残す（データ保持のため）
    }

    /**
     * 有効化処理
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    protected function doEnable($arrPlugin)
    {
        // 特に処理なし
    }

    /**
     * 無効化処理
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    protected function doDisable($arrPlugin)
    {
        // 特に処理なし
    }

    /**
     * 処理の介入箇所とコールバック関数を設定
     *
     * @param SC_Helper_Plugin $objHelperPlugin
     * @param int $priority
     * @return void
     */
    public function register(SC_Helper_Plugin $objHelperPlugin, $priority)
    {
        if (isset($this->arrSelfInfo['plugin_hook_point'])) {
            $arrHookPoints = $this->arrSelfInfo['plugin_hook_point'];
            foreach ($arrHookPoints as $hook_point) {
                if (isset($hook_point['callback'])) {
                    $hook_point_name = $hook_point['hook_point'];
                    $callback_name = $hook_point['callback'];
                    $objHelperPlugin->addAction($hook_point_name, array($this, $callback_name), $priority);
                }
            }
        }
    }

    /**
     * プラグイン情報を取得
     *
     * @return array
     */
    public function getPluginInfo()
    {
        return $this->arrSelfInfo;
    }

    /**
     * プレフィルタコールバック関数
     *
     * @param string &$source テンプレートのHTMLソース
     * @param LC_Page_Ex $objPage ページオブジェクト
     * @param string $filename テンプレートのファイル名
     * @return void
     */
    public function prefilterTransform(&$source, LC_Page_Ex $objPage, $filename)
    {
        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] prefilterTransform called: filename=' . $filename);

        // マイページログインページ
        if ($filename === 'mypage/login.tpl') {
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Inserting login button for mypage');
            $this->insertLoginButton($source, 'mypage');
        }

        // 購入手続きページ（ログインフォーム）
        if ($filename === 'shopping/index.tpl') {
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Inserting login button for shopping');
            $this->insertLoginButton($source, 'shopping');
        }
    }

    /**
     * ログインボタンを挿入
     *
     * @param string &$source テンプレートソース
     * @param string $context コンテキスト（mypage/shopping）
     * @return void
     */
    protected function insertLoginButton(&$source, $context)
    {
        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] insertLoginButton called: context=' . $context);

        $objHelper = new SC_Helper_EcAuth();
        $config = $objHelper->getConfig();

        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Config: ' . print_r($config, true));

        // 設定がない場合は何もしない
        if (empty($config['client_id'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] client_id is empty, skipping');
            return;
        }

        // ボタン生成
        $button = $this->generateLoginButton($context);
        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Generated button HTML: ' . substr($button, 0, 100) . '...');

        // 挿入位置を探す
        // ログインボタン（type="image" または type="submit"）の後に挿入
        // EC-CUBE 2.25 デフォルトテンプレートは type="image" を使用
        // 属性の順序に依存しない正規表現に修正
        $pattern = '/(<input[^>]*alt=["\']ログイン["\'][^>]*\/>)/iu';

        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Searching for pattern in source (length=' . strlen($source) . ')');

        if (preg_match($pattern, $source, $matches)) {
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Pattern matched: ' . $matches[0]);
            $source = preg_replace(
                $pattern,
                '$1' . "\n" . $button,
                $source,
                1  // 最初の1つだけ置換
            );
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Button inserted successfully');
        } else {
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Pattern not matched, trying fallback');
            // フォールバック: btn_area の最初の </ul> の後に挿入
            $fallbackPattern = '/(<div class="btn_area">.*?<\/ul>)/is';
            if (preg_match($fallbackPattern, $source, $matches)) {
                GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Fallback pattern matched');
                $source = preg_replace(
                    $fallbackPattern,
                    '$1' . "\n" . '<li>' . $button . '</li>',
                    $source,
                    1
                );
            } else {
                GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Fallback pattern also not matched');
            }
        }
    }

    /**
     * ログインボタンのHTMLを生成
     *
     * @param string $context コンテキスト
     * @return string ボタンHTML
     */
    protected function generateLoginButton($context)
    {
        $objHelper = new SC_Helper_EcAuth();
        $config = $objHelper->getConfig();

        $providerName = !empty($config['provider_name']) ? $config['provider_name'] : 'EcAuth';

        // 認可リクエストエンドポイントへのリンク
        // state/code_verifier はこのエンドポイントで生成・保存される
        $authorizeUrl = HTTPS_URL . 'ecauth/authorize.php';

        $html = <<<HTML
<div class="ecauth-login-button" style="margin-top: 15px; text-align: center;">
    <a href="{$authorizeUrl}" class="btn btn-primary" style="background-color: #4285f4; border-color: #4285f4; color: #fff; padding: 10px 20px; text-decoration: none; display: inline-block; border-radius: 4px;">
        {$providerName} でログイン
    </a>
</div>
HTML;

        return $html;
    }

    /**
     * ecauth_subject カラムを追加
     *
     * @return void
     */
    protected function addEcAuthSubjectColumn()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();

        // カラムが存在するか確認
        $columns = $objQuery->listTableFields('dtb_customer');

        if (!in_array('ecauth_subject', $columns)) {
            // カラム追加
            $sql = 'ALTER TABLE dtb_customer ADD ecauth_subject VARCHAR(255)';
            $objQuery->query($sql);

            // インデックス作成
            $sql = 'CREATE INDEX idx_customer_ecauth_subject ON dtb_customer(ecauth_subject)';
            $objQuery->query($sql);

            GC_Utils_Ex::gfPrintLog('EcAuthLogin2: ecauth_subject カラムを追加しました');
        }
    }

    /**
     * 必要なファイルをコピー
     *
     * @return void
     */
    protected function copyFiles()
    {
        $pluginDir = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/';

        // html/ecauth/ ディレクトリ作成とファイルコピー
        $htmlDir = HTML_REALDIR . 'ecauth/';
        if (!is_dir($htmlDir)) {
            mkdir($htmlDir, 0755, true);
        }

        if (is_file($pluginDir . 'html/ecauth/callback.php')) {
            copy($pluginDir . 'html/ecauth/callback.php', $htmlDir . 'callback.php');
        }

        if (is_file($pluginDir . 'html/ecauth/authorize.php')) {
            copy($pluginDir . 'html/ecauth/authorize.php', $htmlDir . 'authorize.php');
        }

        // data/class/ ディレクトリへのファイルコピー
        $classDir = CLASS_REALDIR . 'pages/ecauth/';
        if (!is_dir($classDir)) {
            mkdir($classDir, 0755, true);
        }

        if (is_file($pluginDir . 'data/class/pages/ecauth/LC_Page_EcAuth_Callback.php')) {
            copy(
                $pluginDir . 'data/class/pages/ecauth/LC_Page_EcAuth_Callback.php',
                $classDir . 'LC_Page_EcAuth_Callback.php'
            );
        }

        if (is_file($pluginDir . 'data/class/pages/ecauth/LC_Page_EcAuth_Authorize.php')) {
            copy(
                $pluginDir . 'data/class/pages/ecauth/LC_Page_EcAuth_Authorize.php',
                $classDir . 'LC_Page_EcAuth_Authorize.php'
            );
        }

        // data/class/helper/ へのファイルコピー
        $helperDir = CLASS_REALDIR . 'helper/';
        if (is_file($pluginDir . 'data/class/helper/SC_Helper_EcAuth.php')) {
            copy(
                $pluginDir . 'data/class/helper/SC_Helper_EcAuth.php',
                $helperDir . 'SC_Helper_EcAuth.php'
            );
        }

        // data/class_extends/page_extends/ecauth/ へのファイルコピー
        $extendsDir = CLASS_EX_REALDIR . 'page_extends/ecauth/';
        if (!is_dir($extendsDir)) {
            mkdir($extendsDir, 0755, true);
        }

        if (is_file($pluginDir . 'data/class_extends/page_extends/ecauth/LC_Page_EcAuth_Callback_Ex.php')) {
            copy(
                $pluginDir . 'data/class_extends/page_extends/ecauth/LC_Page_EcAuth_Callback_Ex.php',
                $extendsDir . 'LC_Page_EcAuth_Callback_Ex.php'
            );
        }

        if (is_file($pluginDir . 'data/class_extends/page_extends/ecauth/LC_Page_EcAuth_Authorize_Ex.php')) {
            copy(
                $pluginDir . 'data/class_extends/page_extends/ecauth/LC_Page_EcAuth_Authorize_Ex.php',
                $extendsDir . 'LC_Page_EcAuth_Authorize_Ex.php'
            );
        }

        GC_Utils_Ex::gfPrintLog('EcAuthLogin2: ファイルをコピーしました');
    }

    /**
     * コピーしたファイルを削除
     *
     * @return void
     */
    protected function removeFiles()
    {
        // html/ecauth/ ディレクトリ削除
        $htmlDir = HTML_REALDIR . 'ecauth/';
        if (is_dir($htmlDir)) {
            if (is_file($htmlDir . 'callback.php')) {
                unlink($htmlDir . 'callback.php');
            }
            if (is_file($htmlDir . 'authorize.php')) {
                unlink($htmlDir . 'authorize.php');
            }
            rmdir($htmlDir);
        }

        // data/class/pages/ecauth/ ディレクトリ削除
        $classDir = CLASS_REALDIR . 'pages/ecauth/';
        if (is_dir($classDir)) {
            if (is_file($classDir . 'LC_Page_EcAuth_Callback.php')) {
                unlink($classDir . 'LC_Page_EcAuth_Callback.php');
            }
            if (is_file($classDir . 'LC_Page_EcAuth_Authorize.php')) {
                unlink($classDir . 'LC_Page_EcAuth_Authorize.php');
            }
            rmdir($classDir);
        }

        // data/class/helper/SC_Helper_EcAuth.php 削除
        $helperFile = CLASS_REALDIR . 'helper/SC_Helper_EcAuth.php';
        if (is_file($helperFile)) {
            unlink($helperFile);
        }

        // data/class_extends/page_extends/ecauth/ ディレクトリ削除
        $extendsDir = CLASS_EX_REALDIR . 'page_extends/ecauth/';
        if (is_dir($extendsDir)) {
            if (is_file($extendsDir . 'LC_Page_EcAuth_Callback_Ex.php')) {
                unlink($extendsDir . 'LC_Page_EcAuth_Callback_Ex.php');
            }
            if (is_file($extendsDir . 'LC_Page_EcAuth_Authorize_Ex.php')) {
                unlink($extendsDir . 'LC_Page_EcAuth_Authorize_Ex.php');
            }
            rmdir($extendsDir);
        }

        GC_Utils_Ex::gfPrintLog('EcAuthLogin2: ファイルを削除しました');
    }
}
