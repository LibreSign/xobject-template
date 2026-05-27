# SPDX-FileCopyrightText: 2026 LibreSign
# SPDX-License-Identifier: AGPL-3.0-or-later

FROM php:8.2-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip bash curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN groupadd -g ${GID} app || true \
    && useradd -m -u ${UID} -g ${GID} -s /bin/bash app || true

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
USER app

CMD ["bash"]
