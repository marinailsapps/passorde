# Image for the app, queue and scheduler containers.
#
#   dev         compose.yml: the source is bind-mounted, the container user
#               matches the host user so files stay writable.
#   production  compose.prod.yml: code, vendor (no dev packages) and the built
#               assets baked in, opcache on, runs as www-data. Built by tools/prod-local/build.sh
#               or by your own pipeline, with the profile (docs/RUNBOOK.md).

# Pinned to the exact base image in production (serversideup v5.0.2, PHP 8.3.36,
# Debian trixie; tag and digest below).
# To upgrade: pick a newer 8.3.x-fpm-nginx-trixie-vX.Y.Z tag on Docker Hub, take
# its digest, rebuild and run the checks (docs/RUNBOOK.md "Deploying").
FROM serversideup/php:8.3.36-fpm-nginx-trixie-v5.0.2@sha256:e6e9d1a027d603dd6399adc66cb85f83e474ca11fb521e1c2eb1411a82dd5ff0 AS base

USER root
# gd: required by phpoffice/phpspreadsheet (the stock report's Excel export).
RUN install-php-extensions gd
# The service worker script is always revalidated (phones must not run an old app).
COPY docker/php/nginx/service-worker.conf /etc/nginx/server-opts.d/service-worker.conf
USER www-data


FROM base AS dev

ARG USER_ID=1000
ARG GROUP_ID=1000

USER root
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID} --service nginx
USER www-data


# Composer packages without dev dependencies (no tinker, no test tools). Passorde Connect
# comes from its public repository at the version composer.lock names (no credential needed).
FROM base AS vendor

USER root
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader


# The installation profile for the asset build: the web manifest, icons, colours and the
# phones' storage names are fixed there (docs/RUNBOOK.md "Installation profile"). Empty
# here: the build passes the private profile directory as a named context together with
# its path, or states that there is none:
#   --build-context profile=<directory> --build-arg PASSORDE_PROFILE=/profile
#   --build-arg PASSORDE_PROFILE=none       (the neutral product)
# The image keeps no profile: production mounts it at run time (compose.prod.yml).
FROM scratch AS profile


# What the asset build takes from the profile (scripts/profile-assets.php). With
# APP_ENV=production it stops unless PASSORDE_PROFILE is stated, as the app does at boot:
# an image is never built for the neutral defaults by accident.
FROM base AS brand

ARG PASSORDE_PROFILE
USER root
WORKDIR /src
COPY app/Support/Profile/Profile.php app/Support/Profile/Brand.php app/Support/Profile/
COPY config/passorde.php config/
COPY scripts/profile-assets.php scripts/
COPY --from=profile . /profile
RUN APP_ENV=production PASSORDE_PROFILE="${PASSORDE_PROFILE:-}" php scripts/profile-assets.php /brand


# Vite build: hashed assets in public/build, the service worker in public/sw.js, the
# icons in public/icons (from the profile's logo, else the neutral one).
FROM node:22-alpine AS assets

WORKDIR /app
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY tsconfig.json vite.config.ts eslint.config.js ./
COPY scripts/brand.ts scripts/make-icons.mjs scripts/
COPY --from=brand /brand build-profile
COPY lang lang
COPY resources resources
# The type check covers resources/js tests too; these read the shared search and sales report cases.
COPY tests/Fixtures/search-cases.json tests/Fixtures/sales-report-cases.json tests/Fixtures/wording-cases.json tests/Fixtures/
RUN npm run build


FROM base AS production

# Git SHA of the build, shown in the app (config/server.php "version").
ARG APP_VERSION=dev

ENV APP_VERSION=${APP_VERSION} \
    PHP_OPCACHE_ENABLE=1 \
    HEALTHCHECK_PATH=/up \
    LOG_OUTPUT_LEVEL=warn

USER root
WORKDIR /var/www/html

# Code is owned by root and read-only for www-data; only storage/ and
# bootstrap/cache/ are writable (storage/ is a shared volume in production).
COPY . .
COPY --from=vendor /var/www/html/vendor vendor
COPY --from=assets /app/public/build public/build
COPY --from=assets /app/public/sw.js public/sw.js
COPY --from=assets /app/public/icons public/icons
COPY --from=assets /app/public/favicon.ico public/favicon.ico

# dump-autoload also runs package:discover (composer.json post-autoload-dump).
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
