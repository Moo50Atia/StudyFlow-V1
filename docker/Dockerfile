ARG NODE_IMAGE=node:24-bookworm-slim@sha256:d6aa754f16b3197301076f047b5def2f02ea1dbbc2ca920407d46d7ec7f87b20
ARG PHP_IMAGE=php:8.4-fpm-bookworm@sha256:6bfef8e416977aa41f48e3e42a40c1e08050d24e4a938c6edb421400bff24601
ARG PYTHON_IMAGE=python:3.12-slim-bookworm@sha256:34386ef0cb081344d7ec1c103ba398e6e9f64e9ab3a1509accc92a4e24a07258
ARG NGINX_IMAGE=nginx:1.28-bookworm@sha256:552e7481ca93ffccd046aa658dbbed22caefbc09c66fa7cd247cbb90b8a5c609
ARG COMPOSER_IMAGE=composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c
ARG DOCKER_CLI_IMAGE=docker:29.1.3-cli@sha256:4fa0ee1f3a7e4354c4ea34558b6d4ee32859baf4973d4c8ccc8e7fe3dd730c04
FROM ${DOCKER_CLI_IMAGE} AS docker-cli
FROM ${NODE_IMAGE} AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci && mkdir -p node_modules/.vite && chmod -R a+rwX node_modules
RUN lock_checksum="$(sha256sum package-lock.json)" && printf '%s\n' "${lock_checksum%% *}" > node_modules/.product-lockhash && chmod a+rw node_modules/.product-lockhash
COPY resources resources
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build
FROM ${COMPOSER_IMAGE} AS composer
FROM ${PHP_IMAGE} AS php
RUN apt-get update
RUN apt-get install -y --no-install-recommends libzip-dev libicu-dev libsqlite3-dev qpdf clamdscan libfcgi-bin unzip git && docker-php-ext-install pdo_sqlite pcntl intl zip opcache && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ARG PRODUCT_PROJECT_PATH
ARG PRODUCT_HOME
ARG PRODUCT_UID
ARG PRODUCT_GID
RUN mkdir -p "$(dirname "$PRODUCT_PROJECT_PATH")" "$PRODUCT_HOME" && ln -s /app "$PRODUCT_PROJECT_PATH" && chown "$PRODUCT_UID:$PRODUCT_GID" "$PRODUCT_HOME"
ENV PYTHONPATH=/app
WORKDIR /app
COPY . .
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader && chmod -R a+rwX bootstrap/cache vendor
COPY --from=frontend /app/public/build public/build
COPY docker/php.ini /usr/local/etc/php/conf.d/product.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-product.conf
COPY docker/clamd-client.conf /etc/clamav/product.conf
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]
FROM ${NODE_IMAGE} AS mcp
WORKDIR /app/integrations/notebooklm-mcp
COPY integrations/notebooklm-mcp/package*.json ./
RUN npm ci
COPY integrations/notebooklm-mcp .
RUN npm run build && chmod -R a+rwX node_modules dist
RUN lock_checksum="$(sha256sum package-lock.json)" && printf '%s\n' "${lock_checksum%% *}" > node_modules/.product-lockhash && chmod a+rw node_modules/.product-lockhash
FROM ${PYTHON_IMAGE} AS python
RUN apt-get update
RUN apt-get install -y --no-install-recommends poppler-utils tesseract-ocr tesseract-ocr-ara qpdf ca-certificates && rm -rf /var/lib/apt/lists/*
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=frontend /usr/local/bin/node /usr/local/bin/node
COPY --from=frontend /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
WORKDIR /app
COPY v3_engine/requirements.lock /tmp/requirements.lock
RUN --mount=type=cache,target=/root/.cache/pip pip install -r /tmp/requirements.lock
ARG PRODUCT_PROJECT_PATH
ARG PRODUCT_HOME
ARG PRODUCT_UID
ARG PRODUCT_GID
RUN mkdir -p "$(dirname "$PRODUCT_PROJECT_PATH")" "$PRODUCT_HOME" && ln -s /app "$PRODUCT_PROJECT_PATH" && chown "$PRODUCT_UID:$PRODUCT_GID" "$PRODUCT_HOME"
ENV PYTHONPATH=/app
COPY . .
COPY --from=mcp /app/integrations/notebooklm-mcp /app/integrations/notebooklm-mcp
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["python", "-m", "uvicorn", "v3_engine.bridge:app", "--host", "0.0.0.0", "--port", "8765"]
FROM frontend AS vite
COPY . .
COPY --from=mcp /app/integrations/notebooklm-mcp /app/integrations/notebooklm-mcp
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0", "--port", "5173", "--strictPort"]
FROM ${NGINX_IMAGE} AS web
COPY public /app/public
COPY --from=frontend /app/public/build /app/public/build
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
