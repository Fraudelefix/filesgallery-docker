ARG PHP_IMAGE=php:8.3.33-apache-bookworm
FROM ${PHP_IMAGE}

ARG FILES_GALLERY_VERSION=0.15.3
ARG IMAGICK_VERSION=3.8.1

LABEL org.opencontainers.image.title="Files Gallery local" \
      org.opencontainers.image.description="Files Gallery officiel sur Apache/PHP" \
      org.opencontainers.image.version="${FILES_GALLERY_VERSION}" \
      org.opencontainers.image.source="https://github.com/Fraudelefix/filesgallery-docker"

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg ghostscript imagemagick \
        libfreetype6-dev libheif1 libjpeg62-turbo-dev libmagickwand-dev libonig-dev \
        libpng-dev libwebp-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" exif gd mbstring zip \
    && pecl install imagick-${IMAGICK_VERSION} \
    && docker-php-ext-enable imagick \
    && a2enmod headers \
    && apt-get purge -y --auto-remove libfreetype6-dev libjpeg62-turbo-dev libonig-dev \
        libmagickwand-dev libpng-dev libwebp-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-filesgallery.conf /etc/apache2/conf-available/filesgallery.conf
COPY docker/php-filesgallery.ini /usr/local/etc/php/conf.d/zz-filesgallery.ini
COPY docker/entrypoint.sh /usr/local/bin/filesgallery-entrypoint
COPY docker/config.php /usr/local/share/filesgallery/config.php
COPY docker/admin-config.php /usr/local/share/filesgallery/admin-config.php
COPY app/VERSION /usr/local/share/filesgallery/VERSION
COPY app/index.php /var/www/html/index.php
COPY docker/_filesconfig.php /var/www/html/_filesconfig.php

RUN a2enconf filesgallery \
    && chmod 0555 /usr/local/bin/filesgallery-entrypoint \
    && chown root:root /var/www/html/index.php /var/www/html/_filesconfig.php \
    && build_version="$FILES_GALLERY_VERSION" \
    && . /usr/local/share/filesgallery/VERSION \
    && test "$FILES_GALLERY_VERSION" = "$build_version" \
    && echo "$FILES_GALLERY_SHA256  /var/www/html/index.php" | sha256sum -c -

EXPOSE 80
ENTRYPOINT ["filesgallery-entrypoint"]
CMD ["apache2-foreground"]
