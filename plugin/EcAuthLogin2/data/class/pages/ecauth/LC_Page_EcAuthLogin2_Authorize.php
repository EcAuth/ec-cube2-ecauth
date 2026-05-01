<?php

/*
 * EcAuthLogin2 認可リクエストページ
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
 * B2C ソーシャルログインの認可リクエストページ。
 * state / code_verifier をセッションに保存し、EcAuth の認可エンドポイントへリダイレクトする。
 *
 * 注意: B2C OIDC フェデレーションは後続リリースで正式提供予定であり、本ページは
 * 現段階では実運用での使用を想定していない (テンプレ側のログインボタンも
 * 未挿入)。コード自体は動作するが、UI 文言・顧客プロビジョニング仕様等は
 * 次フェーズで最終調整する。
 */
class LC_Page_EcAuthLogin2_Authorize extends LC_Page
{
    public function init()
    {
        parent::init();
    }

    public function process()
    {
        $this->action();
    }

    public function action()
    {
        $objHelper = new SC_Helper_EcAuthLogin2();
        $config = $objHelper->getConfig();

        if (empty($config['client_id'])) {
            SC_Response_Ex::sendRedirect(HTTPS_URL . 'mypage/login.php');

            return;
        }

        $callbackUrl = HTTPS_URL . 'ecauth/callback.php';
        $authInfo = $objHelper->getAuthorizationUrl($callbackUrl);

        header('Location: ' . $authInfo['url']);
        exit;
    }
}
