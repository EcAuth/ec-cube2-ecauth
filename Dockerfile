FROM php:7.4-apache

# 必要なパッケージのインストール
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        pdo_mysql \
        mysqli \
        zip \
        gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache mod_rewrite 有効化
RUN a2enmod rewrite ssl

# Composer インストール
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# EC-CUBE 2 ダウンロード
ARG ECCUBE_VERSION=2.17.2
RUN curl -L https://github.com/EC-CUBE/ec-cube2/archive/refs/tags/${ECCUBE_VERSION}.tar.gz | tar xz -C /tmp \
    && mv /tmp/ec-cube2-${ECCUBE_VERSION}/* /var/www/html/ \
    && rm -rf /tmp/ec-cube2-${ECCUBE_VERSION}

# プラグインファイルをコピー
COPY plugin/EcAuthLogin2 /var/www/html/data/downloads/plugin/EcAuthLogin2/
COPY data/class/helper/SC_Helper_EcAuth.php /var/www/html/data/downloads/plugin/EcAuthLogin2/data/class/helper/
COPY data/class/pages /var/www/html/data/downloads/plugin/EcAuthLogin2/data/class/pages/
COPY data/class_extends /var/www/html/data/downloads/plugin/EcAuthLogin2/data/class_extends/
COPY html/ecauth /var/www/html/data/downloads/plugin/EcAuthLogin2/html/ecauth/
COPY data/Smarty/templates /var/www/html/data/downloads/plugin/EcAuthLogin2/data/Smarty/templates/

# エントリーポイント
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# 権限設定
RUN chown -R www-data:www-data /var/www/html

WORKDIR /var/www/html

EXPOSE 80 443

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
