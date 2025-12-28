<?php
/**
 * EcAuth コールバックページ
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */
class LC_Page_EcAuth_Callback extends LC_Page_Ex
{
    /**
     * 初期化
     *
     * @return void
     */
    public function init()
    {
        parent::init();
        $this->tpl_title = 'EcAuth ログイン';
    }

    /**
     * ページ処理
     *
     * @return void
     */
    public function process()
    {
        parent::process();
        $this->action();
        $this->sendResponse();
    }

    /**
     * アクション処理
     *
     * @return void
     */
    public function action()
    {
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] === Start callback processing ===');
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] GET params: ' . print_r($_GET, true));

        $objHelper = new SC_Helper_EcAuth();

        // エラーチェック
        if (isset($_GET['error'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Error from IdP: ' . $_GET['error']);
            $this->handleError(
                $_GET['error'],
                isset($_GET['error_description']) ? $_GET['error_description'] : ''
            );

            return;
        }

        // 必須パラメータチェック
        if (empty($_GET['code']) || empty($_GET['state'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Missing required params. code=' . (empty($_GET['code']) ? 'empty' : 'present') . ', state=' . (empty($_GET['state']) ? 'empty' : 'present'));
            $this->handleError('invalid_request', '必須パラメータが不足しています。');

            return;
        }

        $code = $_GET['code'];
        $state = $_GET['state'];
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] code=' . substr($code, 0, 20) . '..., state=' . substr($state, 0, 50) . '...');

        // State 検証
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Validating state...');
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Session state: ' . (isset($_SESSION['ecauth_state']) ? $_SESSION['ecauth_state'] : 'NOT SET'));
        if (!$objHelper->validateState($state)) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] State validation FAILED');
            $this->handleError('invalid_state', 'State パラメータが無効です。');

            return;
        }
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] State validation OK');

        // Code Verifier 取得
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Getting code verifier...');
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Session code_verifier: ' . (isset($_SESSION['ecauth_code_verifier']) ? 'present' : 'NOT SET'));
        $codeVerifier = $objHelper->getAndClearCodeVerifier();
        if (empty($codeVerifier)) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Code verifier is empty');
            $this->handleError('invalid_request', 'PKCE セッションが無効です。');

            return;
        }
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Code verifier OK');

        // コールバックURL生成
        $redirectUri = $this->getCallbackUrl();
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Callback URL: ' . $redirectUri);

        // トークン交換
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Exchanging code for tokens...');
        $tokens = $objHelper->exchangeCodeForTokens($code, $redirectUri, $codeVerifier);
        if ($tokens === false) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Token exchange FAILED');
            $this->handleError('token_error', 'トークンの取得に失敗しました。');

            return;
        }
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Token exchange OK. access_token=' . (isset($tokens['access_token']) ? substr($tokens['access_token'], 0, 20) . '...' : 'NOT SET'));

        // UserInfo 取得（subject のみ）
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Getting user info...');
        $userInfo = $objHelper->getUserInfo($tokens['access_token']);
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] UserInfo result: ' . print_r($userInfo, true));
        if ($userInfo === false || empty($userInfo['sub'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] UserInfo FAILED or sub is empty');
            $this->handleError('userinfo_error', 'ユーザー情報の取得に失敗しました。');

            return;
        }
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] UserInfo OK. sub=' . $userInfo['sub']);

        // 外部IdPユーザー情報を取得（オプション）
        $config = $objHelper->getConfig();
        $externalUserInfo = array();
        if (!empty($config['provider_name'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Getting external user info for provider: ' . $config['provider_name']);
            $externalUserInfo = $objHelper->getExternalUserInfo(
                $tokens['access_token'],
                $config['provider_name']
            );
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] External UserInfo result: ' . print_r($externalUserInfo, true));
            if ($externalUserInfo === false) {
                GC_Utils_Ex::gfPrintLog('[EcAuth Callback] External UserInfo failed, continuing without it');
                $externalUserInfo = array();
            }
        }

        // 顧客を検索または作成
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Finding or creating customer for sub=' . $userInfo['sub']);
        $customer = $objHelper->findOrCreateCustomer($userInfo['sub'], $externalUserInfo);
        if ($customer === false) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Customer creation/lookup FAILED');
            $this->handleError('customer_error', '顧客情報の処理に失敗しました。');

            return;
        }
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Customer OK. customer_id=' . (isset($customer['customer_id']) ? $customer['customer_id'] : 'NOT SET') . ', email=' . (isset($customer['email']) ? $customer['email'] : 'NOT SET'));

        // ログイン処理
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Logging in customer...');
        $objHelper->loginCustomer($customer);
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Login completed');

        // リダイレクト先を決定
        $redirectUrl = $this->getPostLoginRedirectUrl();
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] Redirecting to: ' . $redirectUrl);
        GC_Utils_Ex::gfPrintLog('[EcAuth Callback] === End callback processing ===');

        SC_Response_Ex::sendRedirect($redirectUrl);
    }

    /**
     * エラー処理
     *
     * @param string $error エラーコード
     * @param string $description エラー説明
     * @return void
     */
    protected function handleError($error, $description)
    {
        GC_Utils_Ex::gfPrintLog('EcAuth Callback Error: ' . $error . ' - ' . $description);

        // エラーメッセージを設定してログインページへリダイレクト
        $this->tpl_error = 'ソーシャルログインに失敗しました。';
        if (!empty($description)) {
            $this->tpl_error .= '(' . $description . ')';
        }

        // セッションにエラーメッセージを保存
        $_SESSION['ecauth_error'] = $this->tpl_error;

        // ログインページへリダイレクト
        SC_Response_Ex::sendRedirect(HTTPS_URL . 'mypage/login.php');
    }

    /**
     * コールバックURLを取得
     *
     * @return string コールバックURL
     */
    protected function getCallbackUrl()
    {
        // セッション共有のため常に HTTPS_URL を使用
        return HTTPS_URL . 'ecauth/callback.php';
    }

    /**
     * ログイン後のリダイレクト先を取得
     *
     * @return string リダイレクトURL
     */
    protected function getPostLoginRedirectUrl()
    {
        // セッションに保存されたリダイレクト先があれば使用
        if (!empty($_SESSION['ecauth_redirect_url'])) {
            $url = $_SESSION['ecauth_redirect_url'];
            unset($_SESSION['ecauth_redirect_url']);

            return $url;
        }

        // デフォルトはマイページトップ
        return HTTPS_URL . 'mypage/';
    }
}
