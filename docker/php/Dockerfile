FROM php:8.3-fpm-alpine

# pdo_mysql for the portal DB; gd for thumbnail downscale/JPEG encode.
RUN apk add --no-cache libpng libjpeg-turbo freetype \
 && apk add --no-cache --virtual .build-deps libpng-dev libjpeg-turbo-dev freetype-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
 && apk del .build-deps

# Bump the php-fpm worker count (stock pool caps at 5, which the adb-bound
# thumbnail path saturates). Loaded after the stock www.conf so [www] wins.
COPY zz-pool.conf /usr/local/etc/php-fpm.d/zz-pool.conf

WORKDIR /var/www/front
