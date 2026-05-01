<?php

/*
 * EcAuthLogin2 管理画面パスキー API 中継ページ
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 管理者認証必須の API。LC_Page_Admin_Ex の adminAuthorization() で
 * 未ログイン時は /admin/index.php にリダイレクトされる。
 *  - verify-password: 入力パスワードを検証し、ecauth_subject (UUID v4) を発番して返す
 *  - register-options: EcAuth /v1/b2b/passkey/register/options を中継
 *  - register-verify: EcAuth /v1/b2b/passkey/register/verify を中継
 */

require_once CLASS_EX_REALDIR . 'page_extends/admin/LC_Page_Admin_Ex.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_EcAuthLogin2.php';

class LC_Page_Admin_EcAuthLogin2_PasskeyApi extends LC_Page_Admin_Ex
{
    /** @var string 'verify-password' or 'register-options' or 'register-verify' */
    protected $endpoint;

    /**
     * @param string $endpoint
     */
    public function __construct($endpoint)
    {
        // LC_Page には __construct が定義されていないため parent::__construct は呼ばない
        $this->endpoint = $endpoint;
    }

    public function init()
    {
        parent::init();
        // Admin Ex が adminAuthorization() を内部で実行するため、
        // 未ログインの場合はここに来る前に admin/index.php へリダイレクトされる。
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

        if (!$this->isCsrfTokenValid()) {
            $this->respond(403, array('error' => 'csrf_token_invalid'));

            return;
        }

        $member = $this->getCurrentMember();
        if (empty($member)) {
            $this->respond(401, array('error' => 'not_logged_in'));

            return;
        }

        $objHelper = new SC_Helper_EcAuthLogin2();

        switch ($this->endpoint) {
            case 'verify-password':
                $this->handleVerifyPassword($objHelper, $member);

                return;
            case 'register-options':
                $this->handleRegisterOptions($objHelper, $member);

                return;
            case 'register-verify':
                $this->handleRegisterVerify($objHelper);

                return;
        }

        $this->respond(404, array('error' => 'unknown_endpoint'));
    }

    protected function handleVerifyPassword(SC_Helper_EcAuthLogin2 $objHelper, array $member)
    {
        $body = $this->readJsonBody();
        if (!is_array($body) || empty($body['password'])) {
            $this->respond(400, array('error' => 'password_required'));

            return;
        }

        if (!$this->verifyMemberPassword($member, (string) $body['password'])) {
            $this->respond(401, array('error' => 'invalid_password'));

            return;
        }

        $b2bSubject = $objHelper->ensureB2BUser($member);
        $this->respond(200, array('b2b_subject' => $b2bSubject));
    }

    protected function handleRegisterOptions(SC_Helper_EcAuthLogin2 $objHelper, array $member)
    {
        $body = $this->readJsonBody();
        if (!is_array($body) || empty($body['b2b_subject'])) {
            $this->respond(400, array('error' => 'b2b_subject_required'));

            return;
        }

        $rpId = $objHelper->getRpId();
        $externalId = isset($member['login_id']) ? $member['login_id'] : '';
        $displayName = isset($body['display_name']) ? $body['display_name'] : null;
        $deviceName = isset($body['device_name']) ? $body['device_name'] : null;

        $result = $objHelper->registerOptions($rpId, $body['b2b_subject'], $externalId, $displayName, $deviceName);
        if ($result['status'] !== 200) {
            $this->respond($result['status'], array('error' => 'register_options_failed'));

            return;
        }

        $sessionId = isset($result['data']['session_id']) ? $result['data']['session_id'] : null;
        if ($sessionId === null) {
            $this->respond(502, array('error' => 'invalid_response'));

            return;
        }
        $_SESSION['ecauth_register_session_id'] = $sessionId;

        $options = isset($result['data']['options']) ? $result['data']['options'] : array();

        // EcAuth 側で external_id 解決により別 subject に解決された場合、
        // dtb_member.ecauth_subject を上書き同期する。
        if (is_array($options)) {
            $objHelper->reconcileEcauthSubjectFromOptions($member, $options);
        }

        $this->respond(200, $options);
    }

    protected function handleRegisterVerify(SC_Helper_EcAuthLogin2 $objHelper)
    {
        $body = $this->readJsonBody();
        if (!is_array($body) || !isset($body['response']) || !is_array($body['response'])) {
            $this->respond(400, array('error' => 'invalid_request_body'));

            return;
        }

        $sessionId = isset($_SESSION['ecauth_register_session_id']) ? $_SESSION['ecauth_register_session_id'] : null;
        unset($_SESSION['ecauth_register_session_id']);
        if ($sessionId === null) {
            $this->respond(400, array('error' => 'session_expired'));

            return;
        }

        $this->fixWebAuthnEmptyObjects($body['response']);

        $deviceName = isset($body['device_name']) && $body['device_name'] !== ''
            ? $body['device_name']
            : $this->resolveDeviceNameFromUserAgent(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null);

        $result = $objHelper->registerVerify($sessionId, $body['response'], $deviceName);
        if ($result['status'] !== 200) {
            $this->respond($result['status'], array(
                'error' => 'registration_failed',
                'ecauth_status' => $result['status'],
                'ecauth_response' => $result['data'],
            ));

            return;
        }

        $this->respond(200, $result['data']);
    }

    /**
     * @return array|null
     */
    protected function getCurrentMember()
    {
        $memberId = isset($_SESSION['member_id']) ? (int) $_SESSION['member_id'] : 0;
        if ($memberId <= 0) {
            return null;
        }
        $objQuery = SC_Query_Ex::getSingletonInstance();
        $member = $objQuery->getRow('*', 'dtb_member', 'member_id = ? AND del_flg = 0 AND work = 1', array($memberId));

        return empty($member) ? null : $member;
    }

    /**
     * @return bool
     */
    protected function isCsrfTokenValid()
    {
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        if ($token === '') {
            return false;
        }

        return SC_Helper_Session_Ex::isValidToken(false) || hash_equals(SC_Helper_Session_Ex::getToken(), $token);
    }

    /**
     * @return bool
     */
    protected function verifyMemberPassword(array $member, $password)
    {
        $salt = isset($member['salt']) ? $member['salt'] : '';
        $hashed = SC_Utils_Ex::sfGetHashString($password, $salt);

        return hash_equals((string) $member['password'], (string) $hashed);
    }

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
     * @param string|null $userAgent
     * @return string
     */
    protected function resolveDeviceNameFromUserAgent($userAgent)
    {
        if ($userAgent === null || $userAgent === '') {
            return 'Unknown device';
        }

        $browser = 'Browser';
        if (strpos($userAgent, 'Edg/') !== false || strpos($userAgent, 'Edge/') !== false) {
            $browser = 'Edge';
        } elseif (strpos($userAgent, 'OPR/') !== false || strpos($userAgent, 'Opera') !== false) {
            $browser = 'Opera';
        } elseif (strpos($userAgent, 'Firefox/') !== false) {
            $browser = 'Firefox';
        } elseif (strpos($userAgent, 'Chrome/') !== false) {
            $browser = 'Chrome';
        } elseif (strpos($userAgent, 'Safari/') !== false) {
            $browser = 'Safari';
        }

        $os = 'Unknown OS';
        if (strpos($userAgent, 'iPhone') !== false) {
            $os = 'iOS';
        } elseif (strpos($userAgent, 'iPad') !== false) {
            $os = 'iPadOS';
        } elseif (strpos($userAgent, 'Android') !== false) {
            $os = 'Android';
        } elseif (strpos($userAgent, 'Windows') !== false) {
            $os = 'Windows';
        } elseif (strpos($userAgent, 'Mac OS X') !== false || strpos($userAgent, 'Macintosh') !== false) {
            $os = 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            $os = 'Linux';
        }

        return $browser . ' on ' . $os;
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
