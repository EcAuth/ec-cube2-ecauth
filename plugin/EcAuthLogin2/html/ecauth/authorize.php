<?php

/*
 * EcAuthLogin2 認可リクエストエントリーポイント
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 */

require_once __DIR__ . '/../require.php';
require_once CLASS_REALDIR . 'pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php';

$objPage = new LC_Page_EcAuthLogin2_Authorize();
$objPage->init();
$objPage->process();
