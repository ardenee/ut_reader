FROM php:8.3-apache-bookworm

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="UnrealDB" \
      org.opencontainers.image.description="Unreal package catalogue and dependency service" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.source="https://github.com/ardenee/ut_reader"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        default-mysql-client \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" curl mbstring opcache pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod expires headers reqtimeout rewrite \
    && printf '%s\n' 'ServerTokens Prod' 'ServerSignature Off' 'TraceEnable Off' > /etc/apache2/conf-available/unrealdb-security.conf \
    && a2enconf unrealdb-security \
    && sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY deploy/docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/docker/php-production.ini /usr/local/etc/php/conf.d/zz-unrealdb-production.ini

WORKDIR /var/www/html
COPY . /var/www/html

RUN rm -f /var/www/html/catalog/config.php \
    && chmod 0755 /var/www/html/deploy/docker/entrypoint.sh /var/www/html/deploy/docker/worker-loop.sh /var/www/html/deploy/docker/maintenance-loop.sh \
    && mkdir -p \
        /var/www/html/catalog/storage/cache \
        /var/www/html/catalog/storage/federation/incoming \
        /var/www/html/catalog/storage/games \
        /var/www/html/catalog/storage/jobs \
        /var/www/html/catalog/storage/locks \
        /var/www/html/catalog/storage/upload-bucket \
    && chown -R www-data:www-data /var/www/html/catalog/storage

ENV APP_VERSION="${APP_VERSION}" \
    UNREALDB_CATALOG_CONFIG=/var/www/html/deploy/docker/config.php \
    UNREALDB_STORAGE_PATH=/var/www/html/catalog/storage

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error http://127.0.0.1:8080/catalog/api/v1/live.php >/dev/null || exit 1

ENTRYPOINT ["/var/www/html/deploy/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
