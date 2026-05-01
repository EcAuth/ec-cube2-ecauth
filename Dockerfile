# EC-CUBE 2 公式イメージをベース
# プラグインソースは「アーカイブが展開されるのと同じ場所」にだけ置き、
# その他の配置はメインクラスの install() に委譲する。
FROM ghcr.io/ec-cube/ec-cube2-php:7.4-apache-eccube-2.25.0

# プラグイン本体を data/downloads/plugin/<PLUGIN_CODE>/ に展開した状態で配置する。
# Web インストール経路で tar.gz を展開した場合と全く同じパスに配置することで、
# install() が両経路で同一の挙動になるようにする。
COPY plugin/EcAuthLogin2 /var/www/app/data/downloads/plugin/EcAuthLogin2/

# 開発環境用の自動インストール entrypoint
COPY docker-entrypoint.sh /usr/local/bin/ecauth-docker-entrypoint.sh
RUN chmod +x /usr/local/bin/ecauth-docker-entrypoint.sh \
 && chown -R www-data:www-data /var/www/app/data/downloads/plugin/EcAuthLogin2

WORKDIR /var/www/app

# ベースイメージの entrypoint は /wait-for-pgsql.sh。
# 我々のスクリプトはその後段で実行される必要があるため、
# docker-compose.yml 側で entrypoint を上書きする。
