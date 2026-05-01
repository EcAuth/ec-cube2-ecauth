<?php

/*
 * EcAuthLogin2 プラグインを CLI からインストールする開発環境用スクリプト。
 * Copyright (C) 2026 EcAuth
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * 動作:
 *  - dtb_plugin / dtb_plugin_hookpoint に EcAuthLogin2 を登録（未登録時のみ）
 *  - EcAuthLogin2::install() を呼び出し、ファイルコピーと ALTER TABLE を実行
 *  - 環境変数 ECAUTH_CLIENT_ID / ECAUTH_CLIENT_SECRET / ECAUTH_BASE_URL があれば
 *    dtb_plugin.free_field1 に書き込む
 *
 * 本スクリプトは開発環境専用。本番運用では管理画面「オーナーズストア > プラグインを追加する」
 * からの tar.gz アップロード経路を使用すること。
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

error_reporting(E_ALL);

// EC-CUBE 2.25 の初期化（CLI 経由）
define('HTML_REALDIR', '/var/www/app/html/');
define('HTML2DATA_DIR', '../data/');
define('DATA_REALDIR', HTML_REALDIR . HTML2DATA_DIR);
define('SAFE', true);

require_once DATA_REALDIR . 'vendor/autoload.php';
require_once DATA_REALDIR . 'app_initial.php';

const PLUGIN_CODE = 'EcAuthLogin2';

$objQuery = SC_Query_Ex::getSingletonInstance();

$plugin = $objQuery->getRow('*', 'dtb_plugin', 'plugin_code = ?', array(PLUGIN_CODE));

if (empty($plugin)) {
    require_once DATA_REALDIR . 'downloads/plugin/' . PLUGIN_CODE . '/plugin_info.php';

    $pluginId = $objQuery->nextVal('dtb_plugin_plugin_id');
    $arrPlugin = array(
        'plugin_id' => $pluginId,
        'plugin_code' => plugin_info::$PLUGIN_CODE,
        'plugin_name' => plugin_info::$PLUGIN_NAME,
        'class_name' => plugin_info::$CLASS_NAME,
        'plugin_version' => plugin_info::$PLUGIN_VERSION,
        'compliant_version' => plugin_info::$COMPLIANT_VERSION,
        'author' => plugin_info::$AUTHOR,
        'plugin_description' => plugin_info::$DESCRIPTION,
        'plugin_site_url' => plugin_info::$PLUGIN_SITE_URL,
        'author_site_url' => plugin_info::$AUTHOR_SITE_URL,
        'enable' => 1,
        'priority' => 0,
        'update_date' => 'CURRENT_TIMESTAMP',
    );
    $objQuery->insert('dtb_plugin', $arrPlugin);
    fwrite(STDOUT, "Registered plugin in dtb_plugin (plugin_id={$pluginId})\n");

    foreach (plugin_info::$HOOK_POINTS as $hook) {
        $hookpointId = $objQuery->nextVal('dtb_plugin_hookpoint_plugin_hookpoint_id');
        $objQuery->insert('dtb_plugin_hookpoint', array(
            'plugin_hookpoint_id' => $hookpointId,
            'plugin_id' => $pluginId,
            'hook_point' => $hook[0],
            'callback' => $hook[1],
            'use_flg' => 1,
            'update_date' => 'CURRENT_TIMESTAMP',
        ));
    }
    fwrite(STDOUT, "Registered hook points\n");

    $arrPlugin['plugin_hook_point'] = array_map(static function ($hook) {
        return array('hook_point' => $hook[0], 'callback' => $hook[1]);
    }, plugin_info::$HOOK_POINTS);

    require_once DATA_REALDIR . 'downloads/plugin/' . PLUGIN_CODE . '/EcAuthLogin2.php';
    $objPlugin = new EcAuthLogin2($arrPlugin);
    $objPlugin->install($arrPlugin);
    fwrite(STDOUT, "Executed EcAuthLogin2::install()\n");
} else {
    fwrite(STDOUT, "Plugin already registered; running install() to ensure files are in place\n");
    require_once DATA_REALDIR . 'downloads/plugin/' . PLUGIN_CODE . '/EcAuthLogin2.php';
    $objPlugin = new EcAuthLogin2((array) $plugin);
    $objPlugin->install((array) $plugin);
}

// 環境変数から設定を投入（開発環境用）
$envClientId = getenv('ECAUTH_CLIENT_ID');
$envBaseUrl = getenv('ECAUTH_BASE_URL') ?: getenv('ECAUTH_AUTHORIZATION_ENDPOINT');
if (!empty($envClientId) || !empty($envBaseUrl)) {
    $row = $objQuery->getRow('free_field1', 'dtb_plugin', 'plugin_code = ?', array(PLUGIN_CODE));
    $current = empty($row['free_field1']) ? array() : json_decode($row['free_field1'], true);
    if (!is_array($current)) {
        $current = array();
    }

    if (!empty($envClientId)) {
        $current['client_id'] = $envClientId;
    }
    if (!(in_array(getenv('ECAUTH_CLIENT_SECRET'), array('', '0'), true) || getenv('ECAUTH_CLIENT_SECRET') === array() || getenv('ECAUTH_CLIENT_SECRET') === false)) {
        $current['client_secret'] = getenv('ECAUTH_CLIENT_SECRET');
    }
    if (!(getenv('ECAUTH_BASE_URL') === array() || in_array(getenv('ECAUTH_BASE_URL'), array('', '0'), true) || getenv('ECAUTH_BASE_URL') === false)) {
        $current['ecauth_base_url'] = rtrim(getenv('ECAUTH_BASE_URL'), '/');
    }
    if (!(in_array(getenv('ECAUTH_AUTHORIZATION_ENDPOINT'), array('', '0'), true) || getenv('ECAUTH_AUTHORIZATION_ENDPOINT') === array() || getenv('ECAUTH_AUTHORIZATION_ENDPOINT') === false)) {
        $current['authorization_endpoint'] = getenv('ECAUTH_AUTHORIZATION_ENDPOINT');
    }
    if (!(in_array(getenv('ECAUTH_TOKEN_ENDPOINT'), array('', '0'), true) || getenv('ECAUTH_TOKEN_ENDPOINT') === array() || getenv('ECAUTH_TOKEN_ENDPOINT') === false)) {
        $current['token_endpoint'] = getenv('ECAUTH_TOKEN_ENDPOINT');
    }
    if (!(in_array(getenv('ECAUTH_USERINFO_ENDPOINT'), array('', '0'), true) || getenv('ECAUTH_USERINFO_ENDPOINT') === array() || getenv('ECAUTH_USERINFO_ENDPOINT') === false)) {
        $current['userinfo_endpoint'] = getenv('ECAUTH_USERINFO_ENDPOINT');
    }
    if (!(in_array(getenv('ECAUTH_EXTERNAL_USERINFO_ENDPOINT'), array('', '0'), true) || getenv('ECAUTH_EXTERNAL_USERINFO_ENDPOINT') === array() || getenv('ECAUTH_EXTERNAL_USERINFO_ENDPOINT') === false)) {
        $current['external_userinfo_endpoint'] = getenv('ECAUTH_EXTERNAL_USERINFO_ENDPOINT');
    }
    if (!(in_array(getenv('ECAUTH_PROVIDER_NAME'), array('', '0'), true) || getenv('ECAUTH_PROVIDER_NAME') === array() || getenv('ECAUTH_PROVIDER_NAME') === false)) {
        $current['provider_name'] = getenv('ECAUTH_PROVIDER_NAME');
    }
    if (!(in_array(getenv('ECAUTH_RP_ID'), array('', '0'), true) || getenv('ECAUTH_RP_ID') === array() || getenv('ECAUTH_RP_ID') === false)) {
        $current['rp_id'] = getenv('ECAUTH_RP_ID');
    }

    $objQuery->update(
        'dtb_plugin',
        array(
            'free_field1' => json_encode($current, JSON_UNESCAPED_UNICODE),
            'update_date' => 'CURRENT_TIMESTAMP',
        ),
        'plugin_code = ?',
        array(PLUGIN_CODE)
    );
    fwrite(STDOUT, "Updated configuration from environment variables\n");
}

fwrite(STDOUT, "EcAuthLogin2 plugin installation complete.\n");
