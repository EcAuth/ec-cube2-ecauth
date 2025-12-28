#!/bin/bash
set -e

echo "EcAuthLogin2 プラグインをインストールしています..."

cd /var/www/app

php -d display_errors=1 -r "
error_reporting(E_ALL);

// HTML_REALDIR と DATA_REALDIR を定義（EC-CUBE 2.25 の初期化に必要）
define('HTML_REALDIR', '/var/www/app/html/');
define('HTML2DATA_DIR', '../data/');
define('DATA_REALDIR', HTML_REALDIR . HTML2DATA_DIR);
define('SAFE', true);  // DBセッション等をスキップ

// Composer autoload（polyfill を読み込み）
require_once DATA_REALDIR . 'vendor/autoload.php';

// アプリケーション初期化
require_once DATA_REALDIR . 'app_initial.php';

\$objQuery = SC_Query_Ex::getSingletonInstance();

// プラグインが既にインストールされているか確認
\$plugin = \$objQuery->getRow('*', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));

if (empty(\$plugin)) {
    // plugin_info.php を読み込み
    require_once DATA_REALDIR . 'downloads/plugin/EcAuthLogin2/plugin_info.php';

    // プラグイン情報を登録（EC-CUBE 2.25 のカラム名に対応）
    \$plugin_id = \$objQuery->nextVal('dtb_plugin_plugin_id');
    \$arrPlugin = array(
        'plugin_id' => \$plugin_id,
        'plugin_code' => plugin_info::\$PLUGIN_CODE,
        'plugin_name' => plugin_info::\$PLUGIN_NAME,
        'class_name' => plugin_info::\$CLASS_NAME,
        'plugin_version' => plugin_info::\$PLUGIN_VERSION,
        'compliant_version' => plugin_info::\$COMPLIANT_VERSION,
        'author' => plugin_info::\$AUTHOR,
        'plugin_description' => plugin_info::\$DESCRIPTION,
        'plugin_site_url' => plugin_info::\$PLUGIN_SITE_URL,
        'author_site_url' => plugin_info::\$AUTHOR_SITE_URL,
        'enable' => 1,
        'priority' => 0,
        'update_date' => 'CURRENT_TIMESTAMP',
    );

    \$objQuery->insert('dtb_plugin', \$arrPlugin);
    echo \"Plugin registered in dtb_plugin.\\n\";

    // フックポイント登録
    foreach (plugin_info::\$HOOK_POINTS as \$hook) {
        \$hookpoint_id = \$objQuery->nextVal('dtb_plugin_hookpoint_plugin_hookpoint_id');
        \$arrHook = array(
            'plugin_hookpoint_id' => \$hookpoint_id,
            'plugin_id' => \$plugin_id,
            'hook_point' => \$hook[0],
            'callback' => \$hook[1],
            'use_flg' => 1,
            'update_date' => 'CURRENT_TIMESTAMP',
        );
        \$objQuery->insert('dtb_plugin_hookpoint', \$arrHook);
    }
    echo \"Hook points registered.\\n\";

    // dtb_customer に ecauth_subject カラムを追加（プラグインの install() は循環参照で呼べないため直接実行）
    \$columns = \$objQuery->listTableFields('dtb_customer');
    if (!in_array('ecauth_subject', \$columns)) {
        \$objQuery->query('ALTER TABLE dtb_customer ADD ecauth_subject VARCHAR(255)');
        \$objQuery->query('CREATE INDEX idx_customer_ecauth_subject ON dtb_customer(ecauth_subject)');
        echo \"ecauth_subject column added.\\n\";
    }

    // ファイルコピーは Dockerfile で実行済みのためスキップ
    echo \"Plugin files already copied by Dockerfile.\\n\";
} else {
    echo \"Plugin already installed.\\n\";
}

// EcAuth 設定を環境変数から登録
\$clientId = getenv('ECAUTH_CLIENT_ID');
if (!empty(\$clientId)) {
    \$config = array(
        'client_id' => \$clientId,
        'client_secret' => getenv('ECAUTH_CLIENT_SECRET'),
        'authorization_endpoint' => getenv('ECAUTH_AUTHORIZATION_ENDPOINT'),
        'token_endpoint' => getenv('ECAUTH_TOKEN_ENDPOINT'),
        'userinfo_endpoint' => getenv('ECAUTH_USERINFO_ENDPOINT'),
        'external_userinfo_endpoint' => getenv('ECAUTH_EXTERNAL_USERINFO_ENDPOINT'),
        'provider_name' => getenv('ECAUTH_PROVIDER_NAME') ?: 'federate-oauth2',
    );

    \$objQuery->update(
        'dtb_plugin',
        array('free_field1' => json_encode(\$config, JSON_UNESCAPED_UNICODE)),
        'plugin_code = ?',
        array('EcAuthLogin2')
    );
    echo \"EcAuth configuration saved.\\n\";
}
"

echo "EcAuthLogin2 プラグインのインストールが完了しました"
