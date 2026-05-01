<?php

/*
 * EcAuthLogin2 ヘルパークラス
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

/**
 * EcAuthLogin2 共通ヘルパー
 *
 * - dtb_plugin.free_field1 に保存された設定の取得・保存
 * - B2C ソーシャルログイン用の認可 URL 生成・トークン交換・UserInfo 取得・JIT プロビジョニング
 * - B2B パスキー認証用の EcAuth API 呼び出し（authenticate-options/verify, register-options/verify, list/delete, token 交換）
 * - ClientResolveService 相当の Discovery 解決
 *
 * @package EcAuthLogin2
 */
class SC_Helper_EcAuthLogin2
{
    /** @var string プラグインコード */
    public const PLUGIN_CODE = 'EcAuthLogin2';

    /** @var int PKCE code_verifier の長さ */
    public const CODE_VERIFIER_LENGTH = 64;

    /** @var string Discovery エンドポイントのデフォルト Base URL */
    public const DEFAULT_DISCOVERY_URL = 'https://api.ec-auth.io';

    /** @var string Discovery のクライアント解決パス */
    public const CLIENT_RESOLVE_PATH = '/platform/v1/client-resolve';

    /**
     * プラグイン設定を取得
     *
     * @return array 設定配列（client_id, client_secret, ecauth_base_url, rp_id, ...）
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
     * プラグイン設定を保存（既存設定にマージする）
     *
     * @param array $config 設定配列
     * @return bool
     */
    public function saveConfig($config)
    {
        $current = $this->getConfig();
        $merged = array_merge($current, $config);

        $objQuery = SC_Query_Ex::getSingletonInstance();
        $arrUpdate = array(
            'free_field1' => json_encode($merged, JSON_UNESCAPED_UNICODE),
            'update_date' => 'CURRENT_TIMESTAMP',
        );

        $objQuery->update('dtb_plugin', $arrUpdate, 'plugin_code = ?', array(self::PLUGIN_CODE));

        return true;
    }

    // ========================================================================
    // B2C ソーシャルログイン
    // ========================================================================

    /**
     * 認可URLを生成
     *
     * @param string $redirectUri コールバックURL
     * @return array ['url', 'state', 'code_verifier']
     */
    public function getAuthorizationUrl($redirectUri)
    {
        $config = $this->getConfig();

        $state = $this->generateRandomString(32);
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        $_SESSION['ecauth_state'] = $state;
        $_SESSION['ecauth_code_verifier'] = $codeVerifier;

        $params = array(
            'response_type' => 'code',
            'client_id' => isset($config['client_id']) ? $config['client_id'] : '',
            'redirect_uri' => $redirectUri,
            'scope' => 'openid',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        );

        if (!empty($config['provider_name'])) {
            $params['provider_name'] = $config['provider_name'];
        }

        $endpoint = isset($config['authorization_endpoint']) ? $config['authorization_endpoint'] : '';
        $url = $endpoint . '?' . http_build_query($params);

        return array(
            'url' => $url,
            'state' => $state,
            'code_verifier' => $codeVerifier,
        );
    }

    /**
     * 認可コードをトークンに交換（B2C: PKCE 経路）
     *
     * @param string $code
     * @param string $redirectUri
     * @param string $codeVerifier
     * @return array|false
     */
    public function exchangeCodeForTokens($code, $redirectUri, $codeVerifier)
    {
        $config = $this->getConfig();

        $params = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => isset($config['client_id']) ? $config['client_id'] : '',
            'client_secret' => isset($config['client_secret']) ? $config['client_secret'] : '',
            'code_verifier' => $codeVerifier,
        );

        $endpoint = isset($config['token_endpoint'])
            ? $config['token_endpoint']
            : $this->buildEcAuthUrl('/v1/token');

        $response = $this->httpPostForm($endpoint, $params);
        if ($response === false) {
            return false;
        }

        $tokens = json_decode($response, true);
        if (!is_array($tokens) || isset($tokens['error'])) {
            $this->logSafely('Token exchange error', $tokens);

            return false;
        }

        return $tokens;
    }

    /**
     * UserInfo エンドポイントからユーザー情報を取得（subject のみ）
     *
     * @param string $accessToken
     * @return array|false
     */
    public function getUserInfo($accessToken)
    {
        $config = $this->getConfig();
        $endpoint = isset($config['userinfo_endpoint'])
            ? $config['userinfo_endpoint']
            : $this->buildEcAuthUrl('/v1/userinfo');

        $response = $this->httpGet($endpoint, array(
            'Authorization: Bearer ' . $accessToken,
        ));
        if ($response === false) {
            return false;
        }

        $userInfo = json_decode($response, true);
        if (!is_array($userInfo) || isset($userInfo['error'])) {
            return false;
        }

        return $userInfo;
    }

    /**
     * External UserInfo を取得
     *
     * @param string $accessToken
     * @param string $provider
     * @return array|false
     */
    public function getExternalUserInfo($accessToken, $provider)
    {
        $config = $this->getConfig();
        if (empty($config['external_userinfo_endpoint'])) {
            return false;
        }

        $url = $config['external_userinfo_endpoint'] . '?provider=' . urlencode($provider);
        $response = $this->httpGet($url, array(
            'Authorization: Bearer ' . $accessToken,
        ));
        if ($response === false) {
            return false;
        }

        $userInfo = json_decode($response, true);
        if (!is_array($userInfo) || isset($userInfo['error'])) {
            return false;
        }

        return $userInfo;
    }

    /**
     * 顧客を検索または作成（B2C JIT プロビジョニング）
     *
     * @param string $ecauthSubject
     * @param array $externalUserInfo
     * @return array|false
     */
    public function findOrCreateCustomer($ecauthSubject, $externalUserInfo = array())
    {
        $objQuery = SC_Query_Ex::getSingletonInstance();

        $customer = $objQuery->getRow(
            '*',
            'dtb_customer',
            'ecauth_subject = ? AND del_flg = 0',
            array($ecauthSubject)
        );

        if (!empty($customer)) {
            return $customer;
        }

        $customerId = $objQuery->nextVal('dtb_customer_customer_id');

        $arrCustomer = array(
            'customer_id' => $customerId,
            'ecauth_subject' => $ecauthSubject,
            'status' => 2,
            'create_date' => 'CURRENT_TIMESTAMP',
            'update_date' => 'CURRENT_TIMESTAMP',
            'del_flg' => 0,
            'secret_key' => $this->generateRandomString(32),
            'point' => 0,
        );

        if (!empty($externalUserInfo['name'])) {
            $names = $this->splitName($externalUserInfo['name']);
            $arrCustomer['name01'] = $names['name01'];
            $arrCustomer['name02'] = $names['name02'];
        }
        if (!empty($externalUserInfo['email'])) {
            $arrCustomer['email'] = $externalUserInfo['email'];
        }

        if (empty($arrCustomer['name01'])) {
            $arrCustomer['name01'] = 'EcAuth';
        }
        if (empty($arrCustomer['name02'])) {
            $arrCustomer['name02'] = 'ユーザー';
        }
        if (empty($arrCustomer['email'])) {
            $arrCustomer['email'] = 'ecauth_' . $customerId . '@example.com';
        }

        try {
            $objQuery->insert('dtb_customer', $arrCustomer);
        } catch (Exception $e) {
            return false;
        }

        return $objQuery->getRow(
            '*',
            'dtb_customer',
            'ecauth_subject = ? AND del_flg = 0',
            array($ecauthSubject)
        );
    }

    /**
     * 顧客としてログイン
     *
     * @param array $customer
     * @return void
     */
    public function loginCustomer($customer)
    {
        $objCustomer = new SC_Customer_Ex();

        try {
            $objCustomer->setLogin($customer['email']);
        } catch (Exception $e) {
            // ログのみ
        }

        if (function_exists('session_regenerate_id')) {
            session_regenerate_id(true);
        }
    }

    // ========================================================================
    // B2B パスキー
    // ========================================================================

    /**
     * RP ID を取得（設定 > リクエストホスト の優先順位）
     *
     * @return string
     */
    public function getRpId()
    {
        $config = $this->getConfig();
        if (!empty($config['rp_id'])) {
            return $config['rp_id'];
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        // ポート部分は RP ID には含めない（WebAuthn 仕様）
        $host = preg_replace('/:\d+$/', '', $host);

        return $host;
    }

    /**
     * パスキー認証オプションを取得（client_id 認証のみ）
     *
     * @param string $rpId
     * @param string|null $b2bSubject
     * @return array ['status' => int, 'data' => array]
     */
    public function authenticateOptions($rpId, $b2bSubject = null)
    {
        $body = array(
            'client_id' => $this->getClientId(),
            'rp_id' => $rpId,
        );
        if ($b2bSubject !== null) {
            $body['b2b_subject'] = $b2bSubject;
        }

        return $this->callApi('POST', '/v1/b2b/passkey/authenticate/options', $body);
    }

    /**
     * パスキー認証を検証する
     *
     * @param string $sessionId
     * @param string $redirectUri
     * @param string|null $state
     * @param array $response WebAuthn assertion
     * @return array
     */
    public function authenticateVerify($sessionId, $redirectUri, $state, array $response)
    {
        $body = array(
            'session_id' => $sessionId,
            'client_id' => $this->getClientId(),
            'redirect_uri' => $redirectUri,
            'response' => $response,
        );
        if ($state !== null) {
            $body['state'] = $state;
        }

        return $this->callApi('POST', '/v1/b2b/passkey/authenticate/verify', $body);
    }

    /**
     * パスキー登録オプションを取得（client_id + client_secret）
     *
     * @return array
     */
    public function registerOptions($rpId, $b2bSubject, $externalId, $displayName = null, $deviceName = null)
    {
        $body = array(
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'rp_id' => $rpId,
            'b2b_subject' => $b2bSubject,
            'external_id' => $externalId,
        );
        if ($displayName !== null) {
            $body['display_name'] = $displayName;
        }
        if ($deviceName !== null) {
            $body['device_name'] = $deviceName;
        }

        return $this->callApi('POST', '/v1/b2b/passkey/register/options', $body);
    }

    /**
     * パスキー登録検証
     *
     * @return array
     */
    public function registerVerify($sessionId, array $response, $deviceName = null)
    {
        $body = array(
            'session_id' => $sessionId,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'response' => $response,
        );
        if ($deviceName !== null) {
            $body['device_name'] = $deviceName;
        }

        return $this->callApi('POST', '/v1/b2b/passkey/register/verify', $body);
    }

    /**
     * パスキー一覧取得（Bearer Token 認証）
     */
    public function listPasskeys($accessToken)
    {
        return $this->callApi('GET', '/v1/b2b/passkey/list', null, array(
            'Authorization: Bearer ' . $accessToken,
        ));
    }

    /**
     * パスキー削除（Bearer Token 認証）
     */
    public function deletePasskey($accessToken, $credentialId)
    {
        return $this->callApi('DELETE', '/v1/b2b/passkey/' . urlencode($credentialId), null, array(
            'Authorization: Bearer ' . $accessToken,
        ));
    }

    /**
     * 認可コードをトークンに交換（B2B: client_secret 認証、PKCE なし）
     *
     * @return array
     */
    public function exchangeTokenForB2B($code, $redirectUri)
    {
        $endpoint = $this->buildEcAuthUrl('/v1/token');

        $params = array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
        );

        $response = $this->httpPostForm($endpoint, $params);
        if ($response === false) {
            return array('status' => 500, 'data' => array('error' => 'request_failed'));
        }

        $tokens = json_decode($response, true);
        if (!is_array($tokens)) {
            return array('status' => 502, 'data' => array('error' => 'invalid_response'));
        }

        $status = isset($tokens['error']) ? 400 : 200;

        return array('status' => $status, 'data' => $tokens);
    }

    /**
     * Client ID から Base URL を解決する（Discovery）
     *
     * @return array{success: bool, status: int, base_url?: string, tenant_name?: string, error?: string}
     */
    public function resolveClient($clientId)
    {
        if ($clientId === '' || $clientId === null) {
            return array('success' => false, 'status' => 400, 'error' => 'client_id_empty');
        }

        $discoveryUrl = getenv('ECAUTH_CLIENT_RESOLVE_URL');
        if (!$discoveryUrl) {
            $discoveryUrl = self::DEFAULT_DISCOVERY_URL;
        }

        $url = rtrim($discoveryUrl, '/') . self::CLIENT_RESOLVE_PATH . '?' . http_build_query(array(
            'client_id' => $clientId,
        ));

        $response = $this->httpGet($url, array());
        if ($response === false) {
            return array('success' => false, 'status' => 500, 'error' => 'request_failed');
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return array('success' => false, 'status' => 502, 'error' => 'invalid_response');
        }

        if (!empty($decoded['base_url'])) {
            return array(
                'success' => true,
                'status' => 200,
                'base_url' => rtrim($decoded['base_url'], '/'),
                'tenant_name' => isset($decoded['tenant_name']) ? $decoded['tenant_name'] : null,
            );
        }

        return array('success' => false, 'status' => 404, 'error' => 'not_found');
    }

    /**
     * dtb_member.ecauth_subject が未設定の管理者に UUID v4 を発番して保存する
     *
     * @param array $member dtb_member の行
     * @return string 確保された ecauth_subject
     */
    public function ensureB2BUser($member)
    {
        if (!empty($member['ecauth_subject'])) {
            return $member['ecauth_subject'];
        }

        $subject = $this->generateUuidV4();

        $objQuery = SC_Query_Ex::getSingletonInstance();
        $objQuery->update(
            'dtb_member',
            array(
                'ecauth_subject' => $subject,
                'update_date' => 'CURRENT_TIMESTAMP',
            ),
            'member_id = ?',
            array($member['member_id'])
        );

        return $subject;
    }

    /**
     * EcAuth /register/options が返す user.id (base64url) を Member.ecauth_subject と
     * 突き合わせ、異なっていれば EcAuth 側の値で上書きする。
     * 詳細は ec-cube4-ecauth の PasskeyAuthService::reconcileEcauthSubjectFromOptions を参照。
     *
     * @param array $member dtb_member 行
     * @param array $options EcAuth が返した options
     * @return string 確定した ecauth_subject
     */
    public function reconcileEcauthSubjectFromOptions($member, array $options)
    {
        $current = isset($member['ecauth_subject']) ? $member['ecauth_subject'] : null;

        if (!isset($options['user']['id']) || !is_string($options['user']['id'])) {
            return $current;
        }
        $resolved = $this->base64UrlDecode($options['user']['id']);
        if ($resolved === null || $resolved === '') {
            return $current;
        }
        if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $resolved)) {
            return $current;
        }
        if ($current === $resolved) {
            return $current;
        }

        $objQuery = SC_Query_Ex::getSingletonInstance();
        $objQuery->update(
            'dtb_member',
            array(
                'ecauth_subject' => $resolved,
                'update_date' => 'CURRENT_TIMESTAMP',
            ),
            'member_id = ?',
            array($member['member_id'])
        );

        return $resolved;
    }

    /**
     * id_token (JWT) ペイロードから sub を取り出す。
     *
     * 暗号署名は意図的に検証していない。これは OIDC Core 3.1.3.7.6 が許容する
     * "back-channel direct communication via TLS" 経路で id_token を取得しているため:
     *
     *   - 取得元: POST /v1/token (HTTPS, client_id + client_secret 認証)
     *   - サーバー間直接通信なので TLS server validation が issuer validation を兼ねる
     *
     * 仕様引用 (OIDC Core 3.1.3.7.6):
     *   "If the ID Token is received via direct communication between the Client
     *    and the Token Endpoint, the TLS server validation MAY be used to validate
     *    the issuer in place of checking the token signature."
     *
     * リファレンス: ec-cube4-ecauth の PasskeyAuthService::extractSubFromIdToken と
     * 同等の方針。
     *
     * 防御深化として JWKS による署名検証 (firebase/php-jwt 等) を導入する余地は
     * あるが、4 系・2 系横断で整合させる必要があるため別タスク扱い。
     *
     * @param string $idToken
     * @return string|null
     */
    public function extractSubFromIdToken($idToken)
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode($this->base64UrlDecode($parts[1]), true);
        if (!is_array($payload) || empty($payload['sub'])) {
            return null;
        }
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload['sub'];
    }

    /**
     * State パラメータを検証
     */
    public function validateState($state)
    {
        if (empty($_SESSION['ecauth_state'])) {
            return false;
        }
        $valid = hash_equals($_SESSION['ecauth_state'], $state);
        unset($_SESSION['ecauth_state']);

        return $valid;
    }

    /**
     * Code Verifier をセッションから取得して削除
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

    // ========================================================================
    // Internal: HTTP, Crypto, Helpers
    // ========================================================================

    private function getClientId()
    {
        $config = $this->getConfig();

        return isset($config['client_id']) ? (string) $config['client_id'] : '';
    }

    private function getClientSecret()
    {
        $config = $this->getConfig();

        return isset($config['client_secret']) ? (string) $config['client_secret'] : '';
    }

    private function getEcAuthBaseUrl()
    {
        $config = $this->getConfig();

        return isset($config['ecauth_base_url']) ? rtrim((string) $config['ecauth_base_url'], '/') : '';
    }

    private function buildEcAuthUrl($path)
    {
        return $this->getEcAuthBaseUrl() . $path;
    }

    /**
     * EcAuth API 呼び出し共通処理
     *
     * @param string $method
     * @param string $path
     * @param array|null $body
     * @return array{status: int, data: array}
     */
    private function callApi($method, $path, $body, array $extraHeaders = array())
    {
        $baseUrl = $this->getEcAuthBaseUrl();
        if ($baseUrl === '') {
            return array('status' => 500, 'data' => array('error' => 'ecauth_base_url_not_configured'));
        }
        $url = $baseUrl . $path;

        $headers = array_merge(array('Accept: application/json'), $extraHeaders);
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $response = $this->httpRequest($method, $url, $headers, $payload, $statusCode);
        if ($response === false) {
            return array('status' => $statusCode > 0 ? $statusCode : 500, 'data' => array('error' => 'request_failed'));
        }

        $decoded = json_decode($response, true);
        $data = is_array($decoded) ? $decoded : array();

        if ($statusCode >= 400) {
            $this->logSafely('EcAuth API error path=' . $path . ' status=' . $statusCode, $data);
        }

        return array('status' => $statusCode, 'data' => $data);
    }

    /**
     * @param int $statusCode out
     * @return string|false
     */
    private function httpRequest($method, $url, array $headers, $body, &$statusCode)
    {
        $ch = curl_init();

        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err !== '' && $err !== '0') {
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] curl error: ' . $err);

            return false;
        }

        return $response;
    }

    /**
     * application/x-www-form-urlencoded で POST
     *
     * @return string|false
     */
    private function httpPostForm($url, array $params)
    {
        $statusCode = 0;

        return $this->httpRequest('POST', $url, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ), http_build_query($params), $statusCode);
    }

    /**
     * GET（任意ヘッダ）
     *
     * @return string|false
     */
    private function httpGet($url, array $headers)
    {
        $statusCode = 0;
        $headers = array_merge(array('Accept: application/json'), $headers);

        return $this->httpRequest('GET', $url, $headers, null, $statusCode);
    }

    private function generateCodeVerifier()
    {
        return $this->generateRandomString(self::CODE_VERIFIER_LENGTH);
    }

    private function generateCodeChallenge($codeVerifier)
    {
        return $this->base64UrlEncode(hash('sha256', $codeVerifier, true));
    }

    private function generateRandomString($length)
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
        $charactersLength = strlen($characters);
        $randomString = '';

        if (function_exists('random_bytes')) {
            $bytes = random_bytes($length);
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[ord($bytes[$i]) % $charactersLength];
            }

            return $randomString;
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes($length);
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[ord($bytes[$i]) % $charactersLength];
            }

            return $randomString;
        }
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    private function generateUuidV4()
    {
        $data = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return string|null
     */
    private function base64UrlDecode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function splitName($name)
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return array(
            'name01' => isset($parts[0]) ? $parts[0] : '',
            'name02' => isset($parts[1]) ? $parts[1] : '',
        );
    }

    /**
     * 機密フィールドをマスクしてログ出力
     *
     * @param string $message
     * @param mixed $context
     */
    private function logSafely($message, $context)
    {
        if (is_array($context)) {
            foreach (array('access_token', 'id_token', 'refresh_token', 'client_secret') as $key) {
                if (isset($context[$key])) {
                    $context[$key] = '[REDACTED]';
                }
            }
        }
        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] ' . $message . ' ' . json_encode($context));
    }
}
