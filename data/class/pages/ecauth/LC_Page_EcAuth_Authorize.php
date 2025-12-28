<?php
/**
 * EcAuth 認可リクエストページクラス
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */

require_once CLASS_REALDIR . 'pages/LC_Page.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_EcAuth.php';

/**
 * EcAuth 認可リクエストページ
 *
 * ログインボタンからのリクエストを受け取り、
 * state/code_verifier をセッションに保存してから
 * EcAuth 認可エンドポイントにリダイレクトする
 */
class LC_Page_EcAuth_Authorize extends LC_Page
{
    /**
     * 初期化
     *
     * @return void
     */
    public function init()
    {
        parent::init();
    }

    /**
     * 処理実行
     *
     * @return void
     */
    public function process()
    {
        $this->action();
    }

    /**
     * アクション実行
     *
     * @return void
     */
    public function action()
    {
        GC_Utils_Ex::gfPrintLog('[EcAuth Authorize] === Start authorization request ===');

        $objHelper = new SC_Helper_EcAuth();
        $config = $objHelper->getConfig();

        // 設定がない場合はエラー
        if (empty($config['client_id'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Authorize] ERROR: client_id not configured');
            SC_Response_Ex::sendRedirect(HTTPS_URL . 'mypage/login.php');
            return;
        }

        // コールバック URL を生成
        $callbackUrl = HTTPS_URL . 'ecauth/callback.php';
        GC_Utils_Ex::gfPrintLog('[EcAuth Authorize] Callback URL: ' . $callbackUrl);

        // 認可 URL を生成（state と code_verifier がセッションに保存される）
        $authInfo = $objHelper->getAuthorizationUrl($callbackUrl);

        GC_Utils_Ex::gfPrintLog('[EcAuth Authorize] State saved to session: ' . substr($authInfo['state'], 0, 20) . '...');
        GC_Utils_Ex::gfPrintLog('[EcAuth Authorize] Session ID: ' . session_id());
        GC_Utils_Ex::gfPrintLog('[EcAuth Authorize] Redirecting to: ' . substr($authInfo['url'], 0, 100) . '...');

        // EcAuth 認可エンドポイントにリダイレクト
        // SC_Response_Ex::sendRedirect は外部 URL を許可しないため、直接 header() を使用
        header('Location: ' . $authInfo['url']);
        exit;
    }
}
