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

    # eccube_install.sh は config.php に define('ADMIN_DIR', ...) を書くのみで、
    # html/admin/ ディレクトリ自体のリネームは行わない（Web インストーラ専用）。
    # ADMIN_DIR を admin/ 以外に変更した場合は物理ディレクトリも合わせる必要がある。
    ADMIN_DIR_NAME="${ADMIN_DIR%/}"
    if [ -n "${ADMIN_DIR_NAME}" ] && [ "${ADMIN_DIR_NAME}" != "admin" ]; then
        if [ -d "${ECCUBE_DIR}/html/admin" ] && [ ! -d "${ECCUBE_DIR}/html/${ADMIN_DIR_NAME}" ]; then
            echo "[ecauth-entrypoint] Renaming html/admin -> html/${ADMIN_DIR_NAME} (ADMIN_DIR=${ADMIN_DIR})"
            mv "${ECCUBE_DIR}/html/admin" "${ECCUBE_DIR}/html/${ADMIN_DIR_NAME}"
        fi
    fi
fi

# ----- 管理画面パスワード認証の無効化（開発 / CI 専用） ----------------------
# 本番は data/config/config.php を運営者が直接編集する。ここは
# 「環境変数で切り替えたい」開発・CI のための橋渡しでしかない。
#
# 追記した行はマーカー行とセットで管理し、毎回いったん取り除いてから
# 必要なときだけ足し直す。こうしておかないと、環境変数を外して再起動しても
# 前回の追記が残って無効化が解けない（＝緊急復旧の手順を検証できない）。
# 取り除く対象は自分が書いたマーカー行の直後 1 行だけで、
# インストーラが書いた既存の設定には触れない。
CONFIG_FILE="${ECCUBE_DIR}/data/config/config.php"
CONFIG_MARKER="// ecauth-dev-toggle: ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN"

if [ -f "${CONFIG_FILE}" ]; then
    if grep -qF "${CONFIG_MARKER}" "${CONFIG_FILE}"; then
        sed -i "\|${CONFIG_MARKER}|,+1d" "${CONFIG_FILE}"
    fi

    case "${ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN:-}" in
        1|true|TRUE|True|on|yes)
            # 末尾が PHP の閉じタグ (?>) で終わっていると、追記した define() は
            # PHP の外に出て「レスポンス本文に文字列として出力されるだけ」になり、
            # 定数は定義されない。本体のインストーラ (2.25 の Web インストーラ /
            # eccube_install.sh) は閉じタグを書かないが、運営者が手で編集した
            # ファイルには残っていることがある。閉じタグが最終行なら取り除いてから足す。
            if [ "$(tail -n 1 "${CONFIG_FILE}" | tr -d '[:space:]')" = "?>" ]; then
                sed -i '$ d' "${CONFIG_FILE}"
            fi
            printf '%s\n%s\n' \
                "${CONFIG_MARKER}" \
                "defined('ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN') or define('ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN', true);" \
                >> "${CONFIG_FILE}"
            # 「ファイルに文字列がある」ではなく「PHP として評価して定数が立つ」ことを
            # 確かめる。閉じタグ問題のように、書いてあるのに効いていない状態を
            # 起動ログの段階で検出するため。
            if php -r "require '${CONFIG_FILE}'; exit(defined('ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN') && ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN ? 0 : 1);" >/dev/null 2>&1; then
                echo "[ecauth-entrypoint] Admin password login is DISABLED (config.php)"
            else
                echo "[ecauth-entrypoint] ERROR: ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN was appended but is not defined when config.php is evaluated" >&2
                exit 1
            fi
            ;;
        *)
            echo "[ecauth-entrypoint] Admin password login is enabled (default)"
            ;;
    esac
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
