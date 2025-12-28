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
        $objHelper = new SC_Helper_EcAuth();

        // エラーチェック
        if (isset($_GET['error'])) {
            $this->handleError(
                $_GET['error'],
                isset($_GET['error_description']) ? $_GET['error_description'] : ''
            );

            return;
        }

        // 必須パラメータチェック
        if (empty($_GET['code']) || empty($_GET['state'])) {
            $this->handleError('invalid_request', '必須パラメータが不足しています。');

            return;
        }

        $code = $_GET['code'];
        $state = $_GET['state'];

        // State 検証
        if (!$objHelper->validateState($state)) {
            $this->handleError('invalid_state', 'State パラメータが無効です。');

            return;
        }

        // Code Verifier 取得
        $codeVerifier = $objHelper->getAndClearCodeVerifier();
        if (empty($codeVerifier)) {
            $this->handleError('invalid_request', 'PKCE セッションが無効です。');

            return;
        }

        // コールバックURL生成
        $redirectUri = $this->getCallbackUrl();

        // トークン交換
        $tokens = $objHelper->exchangeCodeForTokens($code, $redirectUri, $codeVerifier);
        if ($tokens === false) {
            $this->handleError('token_error', 'トークンの取得に失敗しました。');

            return;
        }

        // UserInfo 取得（subject のみ）
        $userInfo = $objHelper->getUserInfo($tokens['access_token']);
        if ($userInfo === false || empty($userInfo['sub'])) {
            $this->handleError('userinfo_error', 'ユーザー情報の取得に失敗しました。');

            return;
        }

        // 外部IdPユーザー情報を取得（オプション）
        $config = $objHelper->getConfig();
        $externalUserInfo = array();
        if (!empty($config['provider_name'])) {
            $externalUserInfo = $objHelper->getExternalUserInfo(
                $tokens['access_token'],
                $config['provider_name']
            );
            if ($externalUserInfo === false) {
                $externalUserInfo = array();
            }
        }

        // 顧客を検索または作成
        $customer = $objHelper->findOrCreateCustomer($userInfo['sub'], $externalUserInfo);
        if ($customer === false) {
            $this->handleError('customer_error', '顧客情報の処理に失敗しました。');

            return;
        }

        // ログイン処理
        $objHelper->loginCustomer($customer);

        // リダイレクト先を決定
        $redirectUrl = $this->getPostLoginRedirectUrl();

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
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];

        return $protocol . $host . ROOT_URLPATH . 'ecauth/callback.php';
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
