<?php

/*
 * EcAuthLogin2 必須ファイル一覧
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * PLUGIN_UPLOAD_REALDIR/<PLUGIN_CODE>/ 配下に存在しなければならないファイルの
 * 相対パス一覧を返す。EC-CUBE 本体のディレクトリツリーへは配置せず、この場所から
 * 直接読まれるものが対象。
 *
 * filemap.php（配置表）とは役割が違う。配置表に載っているファイルは
 * インストール／アップデート時に存在検証されるが、載っていないファイルは
 * 検証されないまま「インストール成功」と表示されてしまう。1.0.5 でクラスファイルを
 * 配置表から外した結果この穴が空いたため、別表で検証する (#30)。
 *
 * 欠けたときの影響:
 *   - data/class/ 配下: エントリポイントの require_once が fatal error になる
 *   - config.php: プラグイン管理の「設定」リンクが機能しない
 *   - templates/admin/: is_file() で握り潰されるため、管理画面のパスキーボタンや
 *     メニューが無言で出なくなる（#26 と同種の「フィードバックが無い」状態）
 *
 * @see filemap.php
 * @see EcAuthLogin2::verifyRequiredFiles()
 * @see plugin_update::update()
 */

return array(
    // 共通ヘルパー
    'data/class/helper/SC_Helper_EcAuthLogin2.php',
    'data/class/helper/SC_Helper_EcAuthLogin2_BaseUrl.php',
    'data/class/helper/SC_Helper_EcAuthLogin2_IdToken.php',
    'data/class/helper/SC_Helper_EcAuthLogin2_Jwks.php',

    // フロント側ページクラス
    'data/class/pages/ecauth/LC_Page_EcAuthLogin2_Authorize.php',
    'data/class/pages/ecauth/LC_Page_EcAuthLogin2_Callback.php',
    'data/class/pages/ecauth/LC_Page_EcAuthLogin2_PasskeyApi.php',

    // 管理画面ページクラス
    'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Config.php',
    'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_Passkey.php',
    'data/class/pages/admin/ecauth/LC_Page_Admin_EcAuthLogin2_PasskeyApi.php',

    // 本体が PLUGIN_UPLOAD_REALDIR から直接 require する設定画面エントリ
    'config.php',

    // prefilterTransform が file_get_contents で読む管理画面テンプレート
    'templates/admin/plg_EcAuthLogin2_admin_config.tpl',
    'templates/admin/plg_EcAuthLogin2_admin_login_passkey.tpl',
    'templates/admin/plg_EcAuthLogin2_admin_passkey_list.tpl',
    'templates/admin/plg_EcAuthLogin2_admin_basis_subnavi.tpl',
);
