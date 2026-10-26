ARG WORDPRESS_IMAGE=docker.io/library/wordpress:7.0.2-php8.3-apache@sha256:9fac4d47b61186131ffefb5d966f0045d0eea94bfd7bd40cafae29b78a709d1b

FROM docker.io/library/node:24.7.0-bookworm-slim@sha256:7cffa1b5307fcd0089c55ad60ec55de500cfc0e2c6e4440b1c44af68d288b1b5 AS assets
WORKDIR /src
COPY package.json yarn.lock .yarnrc.yml* ./
COPY themes/mae-storm-eats/assets/src themes/mae-storm-eats/assets/src
COPY tailwind.config.js postcss.config.js tsconfig.json ./
RUN corepack enable && corepack yarn install --immutable && corepack yarn build

FROM docker.io/library/composer:2.8.11@sha256:6133e78c003c0625fb763aa131622f537cd696ed14d98b5caa108db9be02ae9b AS composer
WORKDIR /src
COPY composer.json composer.lock ./
COPY plugins/mae-storm-eats/src plugins/mae-storm-eats/src
RUN composer install --no-dev --no-interaction --prefer-dist --classmap-authoritative

FROM ${WORDPRESS_IMAGE}
ARG APP_VERSION=0.1.0
ARG APP_REVISION=unknown
LABEL org.opencontainers.image.source="https://github.com/drewnorman/mae-storm-eats" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.revision="${APP_REVISION}"
ADD --chmod=0755 https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar /usr/local/bin/wp
COPY --from=composer /src/vendor /usr/src/wordpress/wp-content/vendor
COPY plugins/mae-storm-eats /usr/src/wordpress/wp-content/plugins/mae-storm-eats
COPY themes/mae-storm-eats /usr/src/wordpress/wp-content/themes/mae-storm-eats
COPY --from=assets /src/themes/mae-storm-eats/assets/dist /usr/src/wordpress/wp-content/themes/mae-storm-eats/assets/dist
COPY config/production/wp-config-extra.php /usr/local/etc/mae-storm-eats/wp-config-extra.php
COPY scripts/production/image-entrypoint.sh /usr/local/bin/mae-storm-eats-image-entrypoint
COPY scripts/production/initialize.sh /usr/local/bin/mae-storm-eats-initialize
RUN chmod 0755 /usr/local/bin/mae-storm-eats-image-entrypoint \
    /usr/local/bin/mae-storm-eats-initialize \
    && echo 'ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c  /usr/local/bin/wp' | sha256sum -c - \
    && php -r 'require "/usr/src/wordpress/wp-content/vendor/autoload.php"; exit(class_exists("MaeStorm\\Eats\\Activation") ? 0 : 1);' \
    && chown -R www-data:www-data /usr/src/wordpress/wp-content
ENV APP_VERSION="${APP_VERSION}" \
    WORDPRESS_CONFIG_EXTRA="require '/usr/local/etc/mae-storm-eats/wp-config-extra.php';"
ENTRYPOINT ["mae-storm-eats-image-entrypoint"]
CMD ["apache2-foreground"]
HEALTHCHECK --interval=30s --timeout=5s --retries=5 CMD curl -fsS http://127.0.0.1/wp-login.php >/dev/null || exit 1
