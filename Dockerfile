FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y \
      libfreetype-dev libjpeg62-turbo-dev libpng-dev libzip-dev
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip bcmath opcache
RUN pecl install trader-0.5.1 \
	  && docker-php-ext-enable trader

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Enable Apache modules for web UI
RUN a2enmod rewrite headers

# Set Apache DocumentRoot to public directory
ENV APACHE_DOCUMENT_ROOT /var/www/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

#ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS="0"

WORKDIR /var/www