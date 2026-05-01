<?php
/*
 * EcAuthLogin2 コールバックページ
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

require_once CLASS_REALDIR . 'pages/LC_Page.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_EcAuthLogin2.php';

/**
 * EcAuth コールバックページ。
 *
 * B2C ソーシャルログイン（state が ecauth_state、PKCE 経路）と
 * B2B パスキー（state が ecauth_b2b_state、client_secret 経路）の
 * 双方を扱う。state がどちらに一致するかでフローを切り替える。
 */
class LC_Page_EcAuthLogin2_Callback extends LC_Page_Ex
{
    public function init()
    {
        parent::init();
        $this->tpl_title = 'EcAuth ログイン';
    }

    public function process()
    {
        $this->action();
        $this->sendResponse();
    }

    public function action()
    {
        $objHelper = new SC_Helper_EcAuthLogin2();

        if (isset($_GET['error'])) {
            $this->handleError(
                $_GET['error'],
                isset($_GET['error_description']) ? $_GET['error_description'] : '',
                $this->detectFlowFromSession()
            );

            return;
        }

        if (empty($_GET['code']) || empty($_GET['state'])) {
            $this->handleError('invalid_request', '必須パラメータが不足しています。', $this->detectFlowFromSession());

            return;
        }

        $code = (string) $_GET['code'];
        $state = (string) $_GET['state'];

        // B2B（管理者パスキー）か B2C（ソーシャルログイン）かをセッションで判別
        if (!empty($_SESSION['ecauth_b2b_state']) && hash_equals($_SESSION['ecauth_b2b_state'], $state)) {
            unset($_SESSION['ecauth_b2b_state']);
            $this->handleB2BCallback($objHelper, $code);

            return;
        }

        if (!empty($_SESSION['ecauth_state']) && hash_equals($_SESSION['ecauth_state'], $state)) {
            unset($_SESSION['ecauth_state']);
            $this->handleB2CCallback($objHelper, $code);

            return;
        }

        $this->handleError('invalid_state', 'State パラメータが無効です。', 'unknown');
    }

    /**
     * B2B 管理者パスキーログインのコールバック。
     */
    protected function handleB2BCallback(SC_Helper_EcAuthLogin2 $objHelper, $code)
    {
        $redirectUri = HTTPS_URL . 'ecauth/callback.php';

        $tokenResult = $objHelper->exchangeTokenForB2B($code, $redirectUri);
        if ($tokenResult['status'] !== 200) {
            $this->handleError('token_error', '認証に失敗しました。', 'b2b');

            return;
        }
        $tokenData = $tokenResult['data'];

        $idToken = isset($tokenData['id_token']) ? $tokenData['id_token'] : null;
        if ($idToken === null) {
            $this->handleError('invalid_response', '認証に失敗しました。', 'b2b');

            return;
        }
        $b2bSubject = $objHelper->extractSubFromIdToken($idToken);
        if ($b2bSubject === null) {
            $this->handleError('invalid_id_token', '認証に失敗しました。', 'b2b');

            return;
        }

        $objQuery = SC_Query_Ex::getSingletonInstance();
        $members = $objQuery->select(
            '*',
            'dtb_member',
            'ecauth_subject = ? AND del_flg = 0 AND work = 1',
            array($b2bSubject)
        );
        if (count($members) === 0) {
            $this->handleError('member_not_found', '対応する管理者アカウントが見つかりません。', 'b2b');

            return;
        }
        if (count($members) > 1) {
            // UNIQUE 制約があるため通常起きないが、データ破損時に他人セッションを張らない
            GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Ambiguous ecauth_subject binding: ' . $b2bSubject);
            $this->handleError('ambiguous_member', '管理者アカウントの解決に失敗しました。', 'b2b');

            return;
        }
        $member = $members[0];

        // /v1/b2b/passkey/list 用に access_token を保存
        if (!empty($tokenData['access_token'])) {
            $_SESSION['ecauth_access_token'] = $tokenData['access_token'];
        }

        $this->establishAdminSession($member);

        // 管理者ログイン成功後は /admin/home.php に遷移する
        // (/admin/ は常にログインフォームを表示する仕様のため)
        SC_Response_Ex::sendRedirect(HTTPS_URL . ADMIN_DIR . 'home.php');
    }

    /**
     * B2C ソーシャルログインのコールバック。
     */
    protected function handleB2CCallback(SC_Helper_EcAuthLogin2 $objHelper, $code)
    {
        $codeVerifier = $objHelper->getAndClearCodeVerifier();
        if (empty($codeVerifier)) {
            $this->handleError('invalid_request', 'PKCE セッションが無効です。', 'b2c');

            return;
        }

        $redirectUri = HTTPS_URL . 'ecauth/callback.php';
        $tokens = $objHelper->exchangeCodeForTokens($code, $redirectUri, $codeVerifier);
        if ($tokens === false) {
            $this->handleError('token_error', 'トークンの取得に失敗しました。', 'b2c');

            return;
        }

        $userInfo = $objHelper->getUserInfo($tokens['access_token']);
        if ($userInfo === false || empty($userInfo['sub'])) {
            $this->handleError('userinfo_error', 'ユーザー情報の取得に失敗しました。', 'b2c');

            return;
        }

        $config = $objHelper->getConfig();
        $externalUserInfo = array();
        if (!empty($config['provider_name'])) {
            $external = $objHelper->getExternalUserInfo($tokens['access_token'], $config['provider_name']);
            if (is_array($external)) {
                $externalUserInfo = $external;
            }
        }

        $customer = $objHelper->findOrCreateCustomer($userInfo['sub'], $externalUserInfo);
        if ($customer === false) {
            $this->handleError('customer_error', '顧客情報の処理に失敗しました。', 'b2c');

            return;
        }

        $objHelper->loginCustomer($customer);

        $redirectUrl = HTTPS_URL . 'mypage/';
        if (!empty($_SESSION['ecauth_redirect_url'])) {
            $redirectUrl = $_SESSION['ecauth_redirect_url'];
            unset($_SESSION['ecauth_redirect_url']);
        }

        SC_Response_Ex::sendRedirect($redirectUrl);
    }

    /**
     * 管理者セッションを確立する。
     * EC-CUBE 2 標準の admin/index.php::lfSetLoginSession と同じ手順:
     *  - SC_Session_Ex::regenerateSID() で session fixation 対策
     *  - SC_Session_Ex::SetSession で cert / member_id / login_id / authority / login_name / uniqid / last_login を保存
     *  - $_SESSION['cert'] = CERT_STRING が無いと管理画面の認証判定が通らない
     */
    protected function establishAdminSession(array $member)
    {
        SC_Session_Ex::regenerateSID();

        $objSess = new SC_Session_Ex();
        $objSess->SetSession('cert', CERT_STRING);
        $objSess->SetSession('member_id', (int) $member['member_id']);
        $objSess->SetSession('login_id', isset($member['login_id']) ? $member['login_id'] : '');
        $objSess->SetSession('authority', isset($member['authority']) ? (int) $member['authority'] : 0);
        $objSess->SetSession('login_name', isset($member['name']) ? $member['name'] : '');
        $objSess->SetSession('uniqid', $objSess->getUniqId());
        $lastLogin = !empty($member['login_date']) ? $member['login_date'] : date('Y-m-d H:i:s');
        $objSess->SetSession('last_login', $lastLogin);

        // last_login の更新
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $objQuery->update(
            'dtb_member',
            array('login_date' => 'CURRENT_TIMESTAMP'),
            'member_id = ?',
            array($member['member_id'])
        );
    }

    /**
     * @param string $error
     * @param string $description
     * @param string $flow b2b/b2c/unknown
     */
    protected function handleError($error, $description, $flow)
    {
        GC_Utils_Ex::gfPrintLog('[EcAuthLogin2] Callback error flow=' . $flow . ' error=' . $error . ' description=' . $description);

        $message = 'EcAuth ログインに失敗しました。';
        if (!empty($description)) {
            $message .= '(' . $description . ')';
        }
        $_SESSION['ecauth_error'] = $message;

        if ($flow === 'b2b') {
            // 管理画面のログインフォームへ
            SC_Response_Ex::sendRedirect(HTTPS_URL . ADMIN_DIR);

            return;
        }

        SC_Response_Ex::sendRedirect(HTTPS_URL . 'mypage/login.php');
    }

    /**
     * セッションから現在のフローを推測する（エラー時のリダイレクト先決定用）。
     *
     * @return string
     */
    protected function detectFlowFromSession()
    {
        if (!empty($_SESSION['ecauth_b2b_state'])) {
            return 'b2b';
        }
        if (!empty($_SESSION['ecauth_state'])) {
            return 'b2c';
        }

        return 'unknown';
    }
}
