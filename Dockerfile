ARG PHP_IMAGE=php:8.3.33-apache-bookworm
FROM ${PHP_IMAGE}

ARG IMAGICK_VERSION=3.8.1

LABEL org.opencontainers.image.title="Files Gallery local" \
      org.opencontainers.image.description="Files Gallery officiel sur Apache/PHP" \
      org.opencontainers.image.source="https://github.com/Fraudelefix/filesgallery-docker"

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ffmpeg ghostscript imagemagick \
        libfreetype6-dev libheif1 libjpeg62-turbo-dev libmagickwand-dev libonig-dev \
        libonig5 libpng-dev libwebp-dev libzip-dev libzip4 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" exif gd mbstring zip \
    && pecl install imagick-${IMAGICK_VERSION} \
    && docker-php-ext-enable imagick \
    && a2enmod headers \
    && apt-get purge -y --auto-remove libfreetype6-dev libjpeg62-turbo-dev libonig-dev \
        libmagickwand-dev libpng-dev libwebp-dev libzip-dev \
    && php -m \
    && for extension in exif gd imagick mbstring zip; do php -r "exit(extension_loaded('$extension') ? 0 : 1);"; done \
    && extension_dir="$(php -r 'echo ini_get("extension_dir");')" \
    && test -f "$extension_dir/zip.so" \
    && ! ldd "$extension_dir/zip.so" | grep -q 'not found' \
    && convert -version \
    && convert -list format | grep -Eq '^[[:space:]]*JPEG' \
    && convert -list format | grep -Eq '^[[:space:]]*TIFF' \
    && ffmpeg -version \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-filesgallery.conf /etc/apache2/conf-available/filesgallery.conf
COPY docker/php-filesgallery.ini /usr/local/etc/php/conf.d/zz-filesgallery.ini
COPY docker/entrypoint.sh /usr/local/bin/filesgallery-entrypoint
COPY docker/config.php /usr/local/share/filesgallery/config.php
COPY docker/admin-config.php /usr/local/share/filesgallery/admin-config.php
COPY app/VERSION /usr/local/share/filesgallery/VERSION
COPY docker/_filesconfig.php /var/www/html/_filesconfig.php
COPY docker/acl /usr/local/share/filesgallery/acl

RUN a2enconf filesgallery \
    && chmod 0555 /usr/local/bin/filesgallery-entrypoint \
    && chown root:root /var/www/html/_filesconfig.php \
    && chown -R root:root /usr/local/share/filesgallery/acl \
    && . /usr/local/share/filesgallery/VERSION \
    && test -n "$FILES_GALLERY_VERSION" \
    && test -n "$FILES_GALLERY_UPSTREAM_COMMIT" \
    && test -n "$FILES_GALLERY_SHA256" \
    && test -n "$FILES_GALLERY_URL" \
    && command -v curl \
    && test ! -e /var/www/html/index.php

EXPOSE 80
ENTRYPOINT ["filesgallery-entrypoint"]
CMD ["apache2-foreground"]
