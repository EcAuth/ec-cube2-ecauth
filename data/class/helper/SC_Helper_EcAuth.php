<?php
/**
 * EcAuth ソーシャルログインヘルパークラス
 *
 * @package EcAuthLogin2
 * @version 1.0.0
 */
class SC_Helper_EcAuth
{
    /** @var string プラグインコード */
    const PLUGIN_CODE = 'EcAuthLogin2';

    /** @var int PKCE code_verifier の長さ */
    const CODE_VERIFIER_LENGTH = 64;

    /**
     * プラグイン設定を取得
     *
     * @return array 設定配列
     */
    public function getConfig()
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $plugin = $objQuery->getRow('*', 'dtb_plugin', 'plugin_code = ?', array(self::PLUGIN_CODE));

        if (empty($plugin['free_field1'])) {
            return array();
        }

        $config = json_decode($plugin['free_field1'], true);

        return is_array($config) ? $config : array();
    }

    /**
     * プラグイン設定を保存
     *
     * @param array $config 設定配列
     * @return bool 成功した場合 true
     */
    public function saveConfig($config)
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $arrUpdate = array(
            'free_field1' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'update_date' => 'CURRENT_TIMESTAMP',
        );

        $objQuery->update('dtb_plugin', $arrUpdate, 'plugin_code = ?', array(self::PLUGIN_CODE));

        return true;
    }

    /**
     * 認可URLを生成
     *
     * @param string $redirectUri コールバックURL
     * @return array ['url' => 認可URL, 'state' => stateパラメータ, 'code_verifier' => PKCEコード検証子]
     */
    public function getAuthorizationUrl($redirectUri)
    {
        $config = $this->getConfig();

        // State パラメータ生成
        $state = $this->generateRandomString(32);

        // PKCE code_verifier 生成
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // セッションに保存
        $_SESSION['ecauth_state'] = $state;
        $_SESSION['ecauth_code_verifier'] = $codeVerifier;

        $params = array(
            'response_type' => 'code',
            'client_id' => $config['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => 'openid',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        );

        // provider_name があれば追加
        if (!empty($config['provider_name'])) {
            $params['provider_name'] = $config['provider_name'];
        }

        $url = $config['authorization_endpoint'] . '?' . http_build_query($params);

        return array(
            'url' => $url,
            'state' => $state,
            'code_verifier' => $codeVerifier,
        );
    }

    /**
     * 認可コードをトークンに交換
     *
     * @param string $code 認可コード
     * @param string $redirectUri コールバックURL
     * @param string $codeVerifier PKCE コード検証子
     * @return array|false トークン情報、失敗時は false
     */
    public function exchangeCodeForTokens($code, $redirectUri, $codeVerifier)
    {
        $config = $this->getConfig();

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] exchangeCodeForTokens: token_endpoint=' . $config['token_endpoint']);
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] exchangeCodeForTokens: redirect_uri=' . $redirectUri);
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] exchangeCodeForTokens: client_id=' . $config['client_id']);

        $params = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code_verifier' => $codeVerifier,
        );

        $response = $this->httpPost($config['token_endpoint'], $params);

        if ($response === false) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] exchangeCodeForTokens: httpPost returned false');
            return false;
        }

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] exchangeCodeForTokens: response=' . $response);

        $tokens = json_decode($response, true);

        if (isset($tokens['error'])) {
            GC_Utils_Ex::gfPrintLog(
                'EcAuth Token Error: ' . $tokens['error'] . ' - ' .
                (isset($tokens['error_description']) ? $tokens['error_description'] : '')
            );

            return false;
        }

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] exchangeCodeForTokens: token exchange successful');
        return $tokens;
    }

    /**
     * UserInfo エンドポイントからユーザー情報を取得
     *
     * @param string $accessToken アクセストークン
     * @return array|false ユーザー情報、失敗時は false
     */
    public function getUserInfo($accessToken)
    {
        $config = $this->getConfig();

        $response = $this->httpGet($config['userinfo_endpoint'], $accessToken);

        if ($response === false) {
            return false;
        }

        $userInfo = json_decode($response, true);

        if (isset($userInfo['error'])) {
            GC_Utils_Ex::gfPrintLog(
                'EcAuth UserInfo Error: ' . $userInfo['error'] . ' - ' .
                (isset($userInfo['error_description']) ? $userInfo['error_description'] : '')
            );

            return false;
        }

        return $userInfo;
    }

    /**
     * External UserInfo エンドポイントから外部IdPユーザー情報を取得
     *
     * @param string $accessToken アクセストークン
     * @param string $provider プロバイダー名
     * @return array|false ユーザー情報、失敗時は false
     */
    public function getExternalUserInfo($accessToken, $provider)
    {
        $config = $this->getConfig();

        if (empty($config['external_userinfo_endpoint'])) {
            return false;
        }

        $url = $config['external_userinfo_endpoint'] . '?provider=' . urlencode($provider);

        $response = $this->httpGet($url, $accessToken);

        if ($response === false) {
            return false;
        }

        $userInfo = json_decode($response, true);

        if (isset($userInfo['error'])) {
            GC_Utils_Ex::gfPrintLog(
                'EcAuth External UserInfo Error: ' . $userInfo['error'] . ' - ' .
                (isset($userInfo['error_description']) ? $userInfo['error_description'] : '')
            );

            return false;
        }

        return $userInfo;
    }

    /**
     * 顧客を検索または作成（JITプロビジョニング）
     *
     * @param string $ecauthSubject EcAuth の subject
     * @param array $externalUserInfo 外部IdPユーザー情報（オプション）
     * @return array|false 顧客情報、失敗時は false
     */
    public function findOrCreateCustomer($ecauthSubject, $externalUserInfo = array())
    {
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: subject=' . $ecauthSubject);
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: externalUserInfo=' . print_r($externalUserInfo, true));

        $objQuery = SC_Query_Ex::getSingletonInstance();

        // 既存顧客を検索
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: searching existing customer...');
        $customer = $objQuery->getRow(
            '*',
            'dtb_customer',
            'ecauth_subject = ? AND del_flg = 0',
            array($ecauthSubject)
        );

        if (!empty($customer)) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: found existing customer_id=' . $customer['customer_id']);
            return $customer;
        }

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: customer not found, creating new one...');

        // 新規顧客作成（PostgreSQL ではシーケンスから customer_id を取得）
        $customerId = $objQuery->nextVal('dtb_customer_customer_id');
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: new customer_id=' . $customerId);

        $arrCustomer = array(
            'customer_id' => $customerId,
            'ecauth_subject' => $ecauthSubject,
            'status' => 2, // 本会員
            'create_date' => 'CURRENT_TIMESTAMP',
            'update_date' => 'CURRENT_TIMESTAMP',
            'del_flg' => 0,
            'secret_key' => $this->generateRandomString(32), // NOT NULL
            'point' => 0, // NOT NULL
        );

        // 外部IdP情報からフィールドを設定
        if (!empty($externalUserInfo)) {
            if (isset($externalUserInfo['name'])) {
                $names = $this->splitName($externalUserInfo['name']);
                $arrCustomer['name01'] = $names['name01'];
                $arrCustomer['name02'] = $names['name02'];
            }
            if (isset($externalUserInfo['email'])) {
                $arrCustomer['email'] = $externalUserInfo['email'];
            }
        }

        // 必須フィールドのデフォルト値
        if (empty($arrCustomer['name01'])) {
            $arrCustomer['name01'] = 'EcAuth';
        }
        if (empty($arrCustomer['name02'])) {
            $arrCustomer['name02'] = 'ユーザー';
        }
        // email は NOT NULL のため、一意のダミーメールを設定
        if (empty($arrCustomer['email'])) {
            $arrCustomer['email'] = 'ecauth_' . $customerId . '@example.com';
        }

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: inserting customer=' . print_r($arrCustomer, true));

        try {
            $objQuery->insert('dtb_customer', $arrCustomer);
        } catch (Exception $e) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: insert failed: ' . $e->getMessage());
            return false;
        }

        // 作成した顧客を取得
        $customer = $objQuery->getRow(
            '*',
            'dtb_customer',
            'ecauth_subject = ? AND del_flg = 0',
            array($ecauthSubject)
        );

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] findOrCreateCustomer: created customer_id=' . (isset($customer['customer_id']) ? $customer['customer_id'] : 'NULL'));

        return $customer;
    }

    /**
     * 顧客としてログイン
     *
     * @param array $customer 顧客情報
     * @return void
     */
    public function loginCustomer($customer)
    {
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: customer_id=' . (isset($customer['customer_id']) ? $customer['customer_id'] : 'NULL'));
        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: email=' . (isset($customer['email']) ? $customer['email'] : 'NULL'));

        $objCustomer = new SC_Customer_Ex();

        if (empty($customer['email'])) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: WARNING - email is empty, login may fail');
        }

        try {
            $objCustomer->setLogin($customer['email']);
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: setLogin completed');
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: isLoginSuccess=' . ($objCustomer->isLoginSuccess() ? 'true' : 'false'));
        } catch (Exception $e) {
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: setLogin failed: ' . $e->getMessage());
        }

        // セッション再生成（セキュリティ対策）
        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
            GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: session regenerated');
        }

        GC_Utils_Ex::gfPrintLog('[EcAuth Helper] loginCustomer: completed');
    }

    /**
     * State パラメータを検証
     *
     * @param string $state 受信した state
     * @return bool 有効な場合 true
     */
    public function validateState($state)
    {
        if (empty($_SESSION['ecauth_state'])) {
            return false;
        }

        $valid = hash_equals($_SESSION['ecauth_state'], $state);

        // 使用済みの state を削除
        unset($_SESSION['ecauth_state']);

        return $valid;
    }

    /**
     * Code Verifier をセッションから取得して削除
     *
     * @return string|null Code Verifier
     */
    public function getAndClearCodeVerifier()
    {
        if (empty($_SESSION['ecauth_code_verifier'])) {
            return null;
        }

        $codeVerifier = $_SESSION['ecauth_code_verifier'];
        unset($_SESSION['ecauth_code_verifier']);

        return $codeVerifier;
    }

    /**
     * PKCE code_verifier を生成
     *
     * @return string Code Verifier
     */
    protected function generateCodeVerifier()
    {
        return $this->generateRandomString(self::CODE_VERIFIER_LENGTH);
    }

    /**
     * PKCE code_challenge を生成（S256）
     *
     * @param string $codeVerifier Code Verifier
     * @return string Code Challenge
     */
    protected function generateCodeChallenge($codeVerifier)
    {
        $hash = hash('sha256', $codeVerifier, true);

        return $this->base64UrlEncode($hash);
    }

    /**
     * ランダム文字列を生成
     *
     * @param int $length 長さ
     * @return string ランダム文字列
     */
    protected function generateRandomString($length)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $charactersLength = strlen($characters);
        $randomString = '';

        if (function_exists('random_bytes')) {
            // PHP 7+
            $bytes = random_bytes($length);
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[ord($bytes[$i]) % $charactersLength];
            }
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            // PHP 5.x with OpenSSL
            $bytes = openssl_random_pseudo_bytes($length);
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[ord($bytes[$i]) % $charactersLength];
            }
        } else {
            // フォールバック（非推奨）
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
            }
        }

        return $randomString;
    }

    /**
     * Base64 URL エンコード
     *
     * @param string $data データ
     * @return string エンコード済み文字列
     */
    protected function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * HTTP POST リクエスト
     *
     * @param string $url URL
     * @param array $params POSTパラメータ
     * @return string|false レスポンス
     */
    protected function httpPost($url, $params)
    {
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($error) {
            GC_Utils_Ex::gfPrintLog('EcAuth cURL Error: ' . $error);

            return false;
        }

        if ($httpCode >= 400) {
            GC_Utils_Ex::gfPrintLog('EcAuth HTTP Error: ' . $httpCode . ' - ' . $response);

            return false;
        }

        return $response;
    }

    /**
     * HTTP GET リクエスト（Bearer トークン付き）
     *
     * @param string $url URL
     * @param string $accessToken アクセストークン
     * @return string|false レスポンス
     */
    protected function httpGet($url, $accessToken)
    {
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($error) {
            GC_Utils_Ex::gfPrintLog('EcAuth cURL Error: ' . $error);

            return false;
        }

        if ($httpCode >= 400) {
            GC_Utils_Ex::gfPrintLog('EcAuth HTTP Error: ' . $httpCode . ' - ' . $response);

            return false;
        }

        return $response;
    }

    /**
     * 名前を姓・名に分割
     *
     * @param string $name フルネーム
     * @return array ['name01' => 姓, 'name02' => 名]
     */
    protected function splitName($name)
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return array(
            'name01' => isset($parts[0]) ? $parts[0] : '',
            'name02' => isset($parts[1]) ? $parts[1] : '',
        );
    }
}
