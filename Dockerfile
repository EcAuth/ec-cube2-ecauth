# EC-CUBE 2 公式イメージをベースに使用
FROM ghcr.io/ec-cube/ec-cube2-php:7.4-apache-eccube-2.25.0

# プラグインファイルをコピー（公式イメージのパスは /var/www/app）
COPY plugin/EcAuthLogin2 /var/www/app/data/downloads/plugin/EcAuthLogin2/
COPY data/class/helper/SC_Helper_EcAuth.php /var/www/app/data/downloads/plugin/EcAuthLogin2/data/class/helper/
COPY data/class/pages /var/www/app/data/downloads/plugin/EcAuthLogin2/data/class/pages/
COPY data/class_extends /var/www/app/data/downloads/plugin/EcAuthLogin2/data/class_extends/
COPY html/ecauth /var/www/app/data/downloads/plugin/EcAuthLogin2/html/ecauth/
COPY data/Smarty/templates /var/www/app/data/downloads/plugin/EcAuthLogin2/data/Smarty/templates/

# EC-CUBE が使用するディレクトリにもファイルをコピー
# SC_Helper_EcAuth.php
COPY data/class/helper/SC_Helper_EcAuth.php /var/www/app/data/class/helper/

# callback.php と関連ページクラス
COPY html/ecauth /var/www/app/html/ecauth/
COPY data/class/pages/ecauth /var/www/app/data/class/pages/ecauth/
COPY data/class_extends/page_extends/ecauth /var/www/app/data/class_extends/page_extends/ecauth/

# プラグインインストールスクリプトをコピー
COPY install-plugin.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/install-plugin.sh

# 権限設定
RUN chown -R www-data:www-data /var/www/app/data/downloads/plugin/EcAuthLogin2 \
    && chown -R www-data:www-data /var/www/app/data/class/helper/SC_Helper_EcAuth.php \
    && chown -R www-data:www-data /var/www/app/html/ecauth/ \
    && chown -R www-data:www-data /var/www/app/data/class/pages/ecauth/ \
    && chown -R www-data:www-data /var/www/app/data/class_extends/page_extends/ecauth/

WORKDIR /var/www/app
