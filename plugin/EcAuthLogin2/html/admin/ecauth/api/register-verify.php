<?php

/*
 * EcAuthLogin2 管理画面 パスキー登録検証 API
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

// JSON API は X-CSRF-TOKEN ヘッダで CSRF トークンを送信するため、
// require.php の前に $_POST['transactionid'] へ転記して
// EC-CUBE 2 の組み込み CSRF 検証に通す。
if (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) && empty($_POST['transactionid'])) {
    $_POST['transactionid'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
    $_REQUEST['transactionid'] = $_SERVER['HTTP_X_CSRF_TOKEN'];
}

require_once realpath(__DIR__ . '/../../..') . '/require.php';
require_once HTML_REALDIR . ADMIN_DIR . 'require.php';
require_once CLASS_REALDIR . 'pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php';

$objPage = new LC_Page_Admin_EcAuthLogin2_PasskeyApi('register-verify');
$objPage->init();
$objPage->process();
