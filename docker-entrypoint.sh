#!/bin/bash
set -e

# EC-CUBE インストール（初回のみ）
if [ ! -f /var/www/html/data/config/config.php ] && [ "${ECCUBE_INSTALL_SKIP}" != "true" ]; then
    echo "EC-CUBE 2 をインストールしています..."

    cd /var/www/html

    # eccube_install.sh のデフォルト値を使用
    # 必要に応じて環境変数で上書き可能
    export DBSERVER="${DBSERVER:-db}"
    export DBPORT="${DBPORT:-5432}"

    ./eccube_install.sh pgsql

    echo "EC-CUBE 2 のインストールが完了しました"
fi

# プラグインインストール（EcAuthLogin2）
if [ -d /var/www/html/data/downloads/plugin/EcAuthLogin2 ]; then
    echo "EcAuthLogin2 プラグインをインストールしています..."

    php -r "
        require_once '/var/www/html/data/config/config.php';
        require_once '/var/www/html/data/class/SC_Initial.php';
        \$objInit = new SC_Initial();
        \$objInit->init();

        \$objQuery = SC_Query_Ex::getSingletonInstance();

        // プラグインが既にインストールされているか確認
        \$plugin = \$objQuery->getRow('*', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));

        if (empty(\$plugin)) {
            // plugin_info.php を読み込み
            require_once '/var/www/html/data/downloads/plugin/EcAuthLogin2/plugin_info.php';

            // プラグイン情報を登録
            \$arrPlugin = array(
                'plugin_code' => plugin_info::\$PLUGIN_CODE,
                'plugin_name' => plugin_info::\$PLUGIN_NAME,
                'class_name' => plugin_info::\$CLASS_NAME,
                'plugin_version' => plugin_info::\$PLUGIN_VERSION,
                'compliant_version' => plugin_info::\$COMPLIANT_VERSION,
                'author' => plugin_info::\$AUTHOR,
                'description' => plugin_info::\$DESCRIPTION,
                'plugin_site_url' => plugin_info::\$PLUGIN_SITE_URL,
                'author_site_url' => plugin_info::\$AUTHOR_SITE_URL,
                'enable' => 1,
                'del_flg' => 0,
                'create_date' => 'CURRENT_TIMESTAMP',
                'update_date' => 'CURRENT_TIMESTAMP',
            );

            \$objQuery->insert('dtb_plugin', \$arrPlugin);
            echo \"Plugin registered in dtb_plugin.\\n\";

            // フックポイント登録
            \$plugin_id = \$objQuery->get('plugin_id', 'dtb_plugin', 'plugin_code = ?', array('EcAuthLogin2'));

            foreach (plugin_info::\$HOOK_POINTS as \$hook) {
                \$arrHook = array(
                    'plugin_id' => \$plugin_id,
                    'hook_point' => \$hook[0],
                    'callback' => \$hook[1],
                    'use_flg' => 1,
                    'create_date' => 'CURRENT_TIMESTAMP',
                    'update_date' => 'CURRENT_TIMESTAMP',
                );
                \$objQuery->insert('dtb_plugin_hookpoint', \$arrHook);
            }
            echo \"Hook points registered.\\n\";

            // プラグインの install() メソッド実行
            require_once '/var/www/html/data/downloads/plugin/EcAuthLogin2/EcAuthLogin2.php';
            \$objPlugin = new EcAuthLogin2(\$arrPlugin);
            \$objPlugin->install(\$arrPlugin);
            echo \"Plugin install() executed.\\n\";
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
fi

# 権限設定
chown -R www-data:www-data /var/www/html

exec "$@"
