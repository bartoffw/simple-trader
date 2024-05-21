FROM php:8.3-apache-bookworm

RUN apt-get update && apt-get install -y \
      libfreetype-dev libjpeg62-turbo-dev libpng-dev libzip-dev
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip bcmath opcache
RUN pecl install trader-0.5.1 \
	  && docker-php-ext-enable trader

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

ENV APACHE_DOCUMENT_ROOT /var/www
#ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS="0"

WORKDIR /var/www