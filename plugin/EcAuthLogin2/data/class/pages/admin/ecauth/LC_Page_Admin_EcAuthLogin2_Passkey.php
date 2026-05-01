<?php
/*
 * EcAuthLogin2 管理画面 パスキー管理ページ
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

require_once CLASS_EX_REALDIR . 'page_extends/admin/LC_Page_Admin_Ex.php';
require_once CLASS_REALDIR . 'helper/SC_Helper_EcAuthLogin2.php';

/**
 * パスキー一覧 / 削除画面。
 * - access_token がセッションにあれば EcAuth API で一覧を取得・表示
 * - 削除は POST + transactionid 検証
 * - 追加は JS で /admin/ecauth/api/* を叩いて WebAuthn register を実行
 */
class LC_Page_Admin_EcAuthLogin2_Passkey extends LC_Page_Admin_Ex
{
    public function init()
    {
        parent::init();
        $this->tpl_mainpage = PLUGIN_UPLOAD_REALDIR . 'EcAuthLogin2/templates/admin/plg_EcAuthLogin2_admin_passkey_list.tpl';
        $this->tpl_mainno = 'ownersstore';
        $this->tpl_subno = 'ecauth_login2_passkey';
        $this->tpl_maintitle = 'プラグイン';
        $this->tpl_subtitle = 'パスキー管理';
    }

    public function process()
    {
        $this->action();
        $this->sendResponse();
    }

    public function action()
    {
        $objHelper = new SC_Helper_EcAuthLogin2();

        if (isset($_POST['mode']) && $_POST['mode'] === 'delete') {
            $this->doDelete($objHelper);
        }

        $accessToken = isset($_SESSION['ecauth_access_token']) ? $_SESSION['ecauth_access_token'] : null;
        $this->passkeys = array();
        $this->error_message = null;

        if ($accessToken === null) {
            $this->error_message = 'パスキー一覧を表示するには、パスキーを登録した後、一旦ログアウトして「パスキーでログイン」してください。';
        } else {
            $result = $objHelper->listPasskeys($accessToken);
            if ($result['status'] === 200 && isset($result['data']['passkeys'])) {
                $this->passkeys = $result['data']['passkeys'];
            } else {
                $this->error_message = 'パスキー一覧の取得に失敗しました。EcAuth の設定を確認してください。';
            }
        }

        $this->current_credential_id = isset($_SESSION['ecauth_current_credential_id'])
            ? $_SESSION['ecauth_current_credential_id']
            : null;

        $config = $objHelper->getConfig();
        $this->has_client_secret = !empty($config['client_secret']);
        // CDN は latest ではなく固定バージョンを参照する。
        // バージョン固定の必要性: response.transports / timeout 上書きの挙動に
        // プラグインが依存しているため、auth-js 側の挙動が変わると壊れる。
        // バージョンを上げる際は新版でこれらの仕様が維持されていることを確認すること。
        $this->ecauth_auth_js_url = 'https://cdn.ec-auth.io/auth-js/0.1.3/ecauth-auth.umd.js';
        $this->csrf_token = SC_Helper_Session_Ex::getToken();
    }

    /**
     * @param SC_Helper_EcAuthLogin2 $objHelper
     */
    protected function doDelete($objHelper)
    {
        if (!SC_Helper_Session_Ex::isValidToken()) {
            $this->tpl_onload = "alert('不正なリクエストです。');";

            return;
        }
        $credentialId = isset($_POST['credential_id']) ? trim($_POST['credential_id']) : '';
        if ($credentialId === '') {
            return;
        }
        $accessToken = isset($_SESSION['ecauth_access_token']) ? $_SESSION['ecauth_access_token'] : null;
        if ($accessToken === null) {
            $this->tpl_onload = "alert('パスキーを削除するには、まずパスキーでログインしてください。');";

            return;
        }

        $result = $objHelper->deletePasskey($accessToken, $credentialId);
        if ($result['status'] >= 200 && $result['status'] < 300) {
            $this->tpl_onload = "alert('パスキーを削除しました。');";
        } else {
            $this->tpl_onload = "alert('パスキーの削除に失敗しました。');";
        }
    }
}
