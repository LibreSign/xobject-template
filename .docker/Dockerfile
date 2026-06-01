# SPDX-FileCopyrightText: 2026 LibreSign
# SPDX-License-Identifier: AGPL-3.0-or-later

FROM php:8.2-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip bash curl ca-certificates python3 python3-pip python3-venv \
    && rm -rf /var/lib/apt/lists/*

COPY docs/requirements-docs.txt /tmp/requirements-docs.txt

RUN python3 -m venv /opt/docs-venv \
    && /opt/docs-venv/bin/pip install --no-cache-dir -r /tmp/requirements-docs.txt \
    && ln -sf /opt/docs-venv/bin/mkdocs /usr/local/bin/mkdocs

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

RUN groupadd -g ${GID} app || true \
    && useradd -m -u ${UID} -g ${GID} -s /bin/bash app || true

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html
USER app

CMD ["bash"]
