<?php
/*
 * EcAuthLogin2 パスキー管理画面エントリーポイント
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

require_once realpath(__DIR__ . '/../../') . '/require.php';
require_once HTML_REALDIR . ADMIN_DIR . 'require.php';
require_once CLASS_REALDIR . 'pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php';

$objPage = new LC_Page_Admin_EcAuthLogin2_Passkey();
$objPage->init();
$objPage->process();
