<?php
/*
 * EcAuthLogin2 パスキー認証 API 中継ページ（公開エンドポイント）
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 認証不要のフロント API。
 *  - authenticateOptions: EcAuth /v1/b2b/passkey/authenticate/options を呼び、
 *    session_id をサーバーセッションに保存して options のみ返す
 *  - authenticateVerify: state を生成・保存し、EcAuth /v1/b2b/passkey/authenticate/verify を呼ぶ
 *    結果の redirect_url 等を JSON で返す（フロントが redirect する）
 */

require_once CLASS_REALDIR . 'helper/SC_Helper_EcAuthLogin2.php';

/**
 * フロント側パスキー API は JSON のみ返す軽量エンドポイント。
 * LC_Page を継承するとページレイアウト解決のために dtb_pagelayout を引きに行き
 * 「ページ情報を取得できませんでした」で 500 を返してしまうため、継承せず
 * 必要最小限の機能（セッション + EcAuth API 中継）のみ実装する。
 */
class LC_Page_EcAuthLogin2_PasskeyApi
{
    /** @var string 'authenticate-options' or 'authenticate-verify' */
    protected $endpoint;

    /**
     * @param string $endpoint
     */
    public function __construct($endpoint)
    {
        $this->endpoint = $endpoint;
    }

    public function init()
    {
        // セッションは require.php → app_initial.php → SC_Initial::init で
        // 既に開始済み。ここでの追加初期化は不要。
    }

    public function process()
    {
        $this->action();
    }

    public function action()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->respond(405, array('error' => 'method_not_allowed'));

            return;
        }

        $objHelper = new SC_Helper_EcAuthLogin2();

        if ($this->endpoint === 'authenticate-options') {
            $this->handleAuthenticateOptions($objHelper);

            return;
        }

        if ($this->endpoint === 'authenticate-verify') {
            $this->handleAuthenticateVerify($objHelper);

            return;
        }

        $this->respond(404, array('error' => 'unknown_endpoint'));
    }

    protected function handleAuthenticateOptions(SC_Helper_EcAuthLogin2 $objHelper)
    {
        $rpId = $objHelper->getRpId();
        $result = $objHelper->authenticateOptions($rpId);
        if ($result['status'] !== 200) {
            $this->respond($result['status'], array('error' => 'failed_to_get_options'));

            return;
        }

        $sessionId = isset($result['data']['session_id']) ? $result['data']['session_id'] : null;
        if ($sessionId === null) {
            $this->respond(502, array('error' => 'invalid_response'));

            return;
        }
        $_SESSION['ecauth_passkey_session_id'] = $sessionId;

        $options = isset($result['data']['options']) ? $result['data']['options'] : array();
        $this->respond(200, $options);
    }

    protected function handleAuthenticateVerify(SC_Helper_EcAuthLogin2 $objHelper)
    {
        $body = $this->readJsonBody();
        if (!is_array($body) || !isset($body['response']) || !is_array($body['response'])) {
            $this->respond(400, array('error' => 'invalid_request_body'));

            return;
        }

        $sessionId = isset($_SESSION['ecauth_passkey_session_id']) ? $_SESSION['ecauth_passkey_session_id'] : null;
        unset($_SESSION['ecauth_passkey_session_id']);
        if ($sessionId === null) {
            $this->respond(400, array('error' => 'session_expired'));

            return;
        }

        $this->fixWebAuthnEmptyObjects($body['response']);

        // B2B 用の state を生成し、コールバックページが分岐に使う
        $state = bin2hex(random_bytes(32));
        $_SESSION['ecauth_b2b_state'] = $state;

        $redirectUri = HTTPS_URL . 'ecauth/callback.php';

        $result = $objHelper->authenticateVerify($sessionId, $redirectUri, $state, $body['response']);
        if ($result['status'] !== 200) {
            unset($_SESSION['ecauth_b2b_state']);
            $this->respond($result['status'], array(
                'error' => 'authentication_failed',
                'ecauth_status' => $result['status'],
                'ecauth_response' => $result['data'],
            ));

            return;
        }

        // 認証に使用した credential_id をセッション保存（一覧画面の「使用中」表示用）
        if (!empty($body['response']['id']) && is_string($body['response']['id'])) {
            $_SESSION['ecauth_current_credential_id'] = $body['response']['id'];
        }

        $this->respond(200, $result['data']);
    }

    /**
     * WebAuthn レスポンス内の空オブジェクト（{}） を stdClass に置き換える。
     * json_decode($s, true) は {} を [] に変換し、Fido2.NetLib のデシリアライズが失敗するため。
     */
    protected function fixWebAuthnEmptyObjects(array &$response)
    {
        if (
            array_key_exists('clientExtensionResults', $response)
            && is_array($response['clientExtensionResults'])
            && empty($response['clientExtensionResults'])
        ) {
            $response['clientExtensionResults'] = new stdClass();
        }
    }

    /**
     * @return mixed
     */
    protected function readJsonBody()
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return null;
        }

        return json_decode($raw, true);
    }

    /**
     * @param int $status
     * @param mixed $body
     */
    protected function respond($status, $body)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
