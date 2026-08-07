<?php

/*
 * EcAuthLogin2 ファイル配置表
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * [プラグイン内パス => コピー先絶対パス] の対応表を返す。
 *
 * クラスではなく「配列を return するだけのファイル」にしているのは、
 * アップデート経路で新バージョンの配置表を確実に読むため。
 * プラグインが有効な状態では SC_Helper_Plugin::load() がリクエスト冒頭で
 * PLUGIN_UPLOAD_REALDIR/EcAuthLogin2/EcAuthLogin2.php を require_once 済みで、
 * アップデート中に同じパスを require_once しても新しい定義は読み込まれない
 * （クラスの再定義もできない）。配列を返すファイルなら require で何度でも
 * 読み直せるため、plugin_update.php が新バージョンの配置表を参照できる。
 *
 * @see EcAuthLogin2::getFileMap()
 * @see plugin_update::update()
 */

return array(
    // 共通ヘルパー
    'data/class/helper/SC_Helper_EcAuthLogin2.php'
        => 'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2.php',
    // id_token 検証まわり（EcAuthDocs #101）。SC_Helper_EcAuthLogin2 が
    // require_once するため、コピー漏れがあると認証が丸ごと落ちる。
    'data/class/helper/SC_Helper_EcAuthLogin2_BaseUrl.php'
        => 'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2_BaseUrl.php',
    'data/class/helper/SC_Helper_EcAuthLogin2_IdToken.php'
        => 'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2_IdToken.php',
    'data/class/helper/SC_Helper_EcAuthLogin2_Jwks.php'
        => 'CLASS_REALDIR:helper/SC_Helper_EcAuthLogin2_Jwks.php',

    // B2C ソーシャルログイン用ページクラス
    'data/class/pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php'
        => 'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php',
    'data/class/pages/ecauth/LC_Page_EcAuthLogin2_Callback.php'
        => 'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_Callback.php',

    // B2B 管理画面ページクラス
    'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php'
        => 'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php',
    'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php'
        => 'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php',
    'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php'
        => 'CLASS_REALDIR:pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php',
    'data/class/pages/ecauth/LC_Page_EcAuthLogin2_PasskeyApi.php'
        => 'CLASS_REALDIR:pages/ecauth/LC_Page_EcAuthLogin2_PasskeyApi.php',

    // B2C ソーシャルログイン用エントリポイント
    'html/ecauth/authorize.php' => 'HTML_REALDIR:ecauth/authorize.php',
    'html/ecauth/callback.php' => 'HTML_REALDIR:ecauth/callback.php',

    // B2B パスキー認証 API（フロント側、認証不要）
    'html/ecauth/passkey/authenticate-options.php'
        => 'HTML_REALDIR:ecauth/passkey/authenticate-options.php',
    'html/ecauth/passkey/authenticate-verify.php'
        => 'HTML_REALDIR:ecauth/passkey/authenticate-verify.php',

    // B2B パスキー登録 API（管理画面、管理者認証必須）
    'html/admin/ecauth/passkey.php' => 'ADMIN_HTML_REALDIR:ecauth/passkey.php',
    'html/admin/ecauth/api/verify-password.php'
        => 'ADMIN_HTML_REALDIR:ecauth/api/verify-password.php',
    'html/admin/ecauth/api/register-options.php'
        => 'ADMIN_HTML_REALDIR:ecauth/api/register-options.php',
    'html/admin/ecauth/api/register-verify.php'
        => 'ADMIN_HTML_REALDIR:ecauth/api/register-verify.php',

    // 管理画面プラグイン管理「設定」リンクは
    // PLUGIN_UPLOAD_REALDIR/<PLUGIN_CODE>/config.php （= プラグインルートの config.php）
    // を直接 require_once する仕様のため、ファイルコピーは不要。
    // tar.gz に config.php が含まれていれば設定リンクが自動的に有効になる。
    //
    // templates/ 配下も同様に PLUGIN_UPLOAD_REALDIR から直接読まれるため
    // （@see EcAuthLogin2::insertAdminPasskeyScript()）ここには載せない。
);
