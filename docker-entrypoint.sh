#!/bin/bash
#
# ec-cube2-ecauth 開発環境用 Docker エントリポイント。
#
# - DB の readiness を待つ
# - EC-CUBE 本体が未インストールならインストールする
# - EcAuthLogin2 プラグインを dtb_plugin / dtb_plugin_hookpoint に登録し、
#   メインクラスの install() でファイルコピー + ALTER TABLE まで実行する
# - その後ベースコマンド（apache2-foreground）に exec する
#
# CI で「Web インストーラ経由のインストール」を検証したい場合は
# SKIP_PLUGIN_INSTALL=true を環境変数で渡すことで、本スクリプトのプラグイン
# 自動インストールをスキップできる（素の EC-CUBE が起動する）。
#
# 本番経路は管理画面「オーナーズストア > プラグインを追加する」からの tar.gz
# アップロードのみとし、本スクリプトには依存しない。

set -e

ECCUBE_DIR=/var/www/app
PLUGIN_CODE=EcAuthLogin2

DB_HOST="${DB_SERVER:-postgres}"
DB_PORT_LOCAL="${DB_PORT:-5432}"
DB_USER_LOCAL="${DB_USER:-eccube_db_user}"
DB_NAME_LOCAL="${DB_NAME:-eccube_db}"
DB_PASSWORD_LOCAL="${DB_PASSWORD:-password}"

echo "[ecauth-entrypoint] Waiting for database ${DB_HOST}:${DB_PORT_LOCAL} ..."
i=0
until PGPASSWORD="${DB_PASSWORD_LOCAL}" psql \
        -h "${DB_HOST}" -p "${DB_PORT_LOCAL}" \
        -U "${DB_USER_LOCAL}" -d "${DB_NAME_LOCAL}" \
        -c "SELECT 1" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "${i}" -ge 60 ]; then
        echo "[ecauth-entrypoint] Database did not become ready in 60 seconds; giving up." >&2
        exit 1
    fi
    sleep 1
done
echo "[ecauth-entrypoint] Database is ready"

# ----- EC-CUBE 本体インストール ---------------------------------------------
if [ ! -f "${ECCUBE_DIR}/data/config/config.php" ] && [ "${ECCUBE_INSTALL_SKIP}" != "true" ]; then
    echo "[ecauth-entrypoint] Installing EC-CUBE 2 core ..."
    cd "${ECCUBE_DIR}"
    export DBSERVER="${DB_HOST}"
    export DBPORT="${DB_PORT_LOCAL}"
    ./eccube_install.sh pgsql
fi

# ----- プラグイン自動インストール（開発環境専用） ----------------------------
if [ "${SKIP_PLUGIN_INSTALL}" = "true" ]; then
    echo "[ecauth-entrypoint] SKIP_PLUGIN_INSTALL=true; skipping plugin auto-install"
elif [ ! -d "${ECCUBE_DIR}/data/downloads/plugin/${PLUGIN_CODE}" ]; then
    echo "[ecauth-entrypoint] Plugin source not found at data/downloads/plugin/${PLUGIN_CODE}; skipping"
else
    echo "[ecauth-entrypoint] Installing ${PLUGIN_CODE} plugin ..."
    cd "${ECCUBE_DIR}"
    php -d display_errors=1 -d error_reporting=-1 \
        "data/downloads/plugin/${PLUGIN_CODE}/tools/install-plugin.php"
fi

# 権限調整（コピーされたファイルを Apache から読めるように）
chown -R www-data:www-data "${ECCUBE_DIR}" 2>/dev/null || true

exec "$@"
