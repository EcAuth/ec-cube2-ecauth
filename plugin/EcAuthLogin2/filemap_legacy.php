<?php

/*
 * EcAuthLogin2 旧配置ファイルの一覧
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 1.0.4 以前が EC-CUBE 本体の data/class/ 配下へコピーしていたクラスファイルの
 * 配置先一覧を返す。1.0.5 以降はこれらをコピーせず PLUGIN_UPLOAD_REALDIR から
 * 直接読むため、旧バージョンからのアップデート／アンインストール時に残骸として
 * 残る。これを消すための表 (#30)。
 *
 * filemap.php と同じく「配列を return するだけのファイル」にしている。
 * アップデート経路では旧バージョンの EcAuthLogin2 クラスがロード済みで
 * 読み直せないため、plugin_update.php が新バージョンのこのファイルを
 * require して参照する。
 *
 * 残骸が残っても実害は無い（新バージョンはこのパスを読まない）が、コアの
 * ディレクトリツリーにプラグイン由来のファイルが混在したままになるため削除する。
 *
 * @see filemap.php
 * @see EcAuthLogin2::removeLegacyClassFiles()
 * @see plugin_update::removeLegacyClassFiles()
 */

return array(
    // 共通ヘルパー
    'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2.php',
    'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2_BaseUrl.php',
    'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2_IdToken.php',
    'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2_Jwks.php',

    // B2C ソーシャルログイン用ページクラス
    'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php',
    'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_Callback.php',
    'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_PasskeyApi.php',

    // B2B 管理画面ページクラス
    'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php',
    'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php',
    'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php',
);
