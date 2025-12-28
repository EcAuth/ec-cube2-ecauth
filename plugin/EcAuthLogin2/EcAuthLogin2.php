<?php
/**
 * EcAuthLogin2 プラグインメインクラス
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */
class EcAuthLogin2 extends SC_Plugin_Base
{
    /**
     * コンストラクタ
     *
     * @param array $arrSelfInfo プラグイン情報
     */
    public function __construct(array $arrSelfInfo)
    {
        parent::__construct($arrSelfInfo);
    }

    /**
     * インストール
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    public function install($arrPlugin)
    {
        // dtb_customer に ecauth_subject カラムを追加
        $this->addEcAuthSubjectColumn();

        // 必要なファイルをコピー
        $this->copyFiles();
    }

    /**
     * アンインストール
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    public function uninstall($arrPlugin)
    {
        // コピーしたファイルを削除
        $this->removeFiles();

        // カラムは残す（データ保持のため）
    }

    /**
     * 有効化
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    public function enable($arrPlugin)
    {
        // 特に処理なし
    }

    /**
     * 無効化
     *
     * @param array $arrPlugin プラグイン情報
     * @return void
     */
    public function disable($arrPlugin)
    {
        // 特に処理なし
    }

    /**
     * 処理の介入箇所とコールバック関数を設定
     *
     * @param SC_Helper_Plugin $objHelperPlugin
     * @return void
     */
    public function register(SC_Helper_Plugin $objHelperPlugin)
    {
        $priority = null;

        return parent::register($objHelperPlugin, $priority);
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
        // マイページログインページ
        if ($filename === 'mypage/login.tpl') {
            $this->insertLoginButton($source, 'mypage');
        }

        // 購入手続きページ（ログインフォーム）
        if ($filename === 'shopping/index.tpl') {
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
        $objHelper = new SC_Helper_EcAuth();
        $config = $objHelper->getConfig();

        // 設定がない場合は何もしない
        if (empty($config['client_id'])) {
            return;
        }

        // ボタン生成
        $button = $this->generateLoginButton($context);

        // 挿入位置を探す
        // ログインボタンの後に挿入
        $pattern = '/(<input[^>]*type=["\']submit["\'][^>]*class=["\'][^"\']*btn[^"\']*["\'][^>]*>)/i';

        if (preg_match($pattern, $source)) {
            $source = preg_replace(
                $pattern,
                '$1' . "\n" . $button,
                $source,
                1  // 最初の1つだけ置換
            );
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
        $callbackUrl = HTTPS_URL . 'ecauth/callback.php';
        $authInfo = $objHelper->getAuthorizationUrl($callbackUrl);
        $config = $objHelper->getConfig();

        $providerName = !empty($config['provider_name']) ? $config['provider_name'] : 'EcAuth';

        $html = <<<HTML
<div class="ecauth-login-button" style="margin-top: 15px; text-align: center;">
    <a href="{$authInfo['url']}" class="btn btn-primary" style="background-color: #4285f4; border-color: #4285f4; color: #fff; padding: 10px 20px; text-decoration: none; display: inline-block; border-radius: 4px;">
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
            rmdir($htmlDir);
        }

        // data/class/pages/ecauth/ ディレクトリ削除
        $classDir = CLASS_REALDIR . 'pages/ecauth/';
        if (is_dir($classDir)) {
            if (is_file($classDir . 'LC_Page_EcAuth_Callback.php')) {
                unlink($classDir . 'LC_Page_EcAuth_Callback.php');
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
            rmdir($extendsDir);
        }

        GC_Utils_Ex::gfPrintLog('EcAuthLogin2: ファイルを削除しました');
    }
}
