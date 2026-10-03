FROM php:8.4-apache

RUN pecl install apcu \
 && docker-php-ext-install pdo_mysql \
 && docker-php-ext-enable apcu opcache

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

COPY backend /var/www/backend
COPY frontend /var/www/html
COPY data /var/www/data
