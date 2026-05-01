<?php
/*
 * EcAuthLogin2 設定画面エントリポイント
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * EC-CUBE 2 のプラグイン管理画面の「設定」リンクから
 * /admin/load_plugin_config.php 内で require_once される。
 * その時点で /admin/require.php と admin 認証チェックは完了済み。
 */

require_once CLASS_REALDIR . 'pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php';

$objPage = new LC_Page_Admin_EcAuthLogin2_Config();
$objPage->init();
$objPage->process();
