
FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*


RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    gd \
    zip \
    opcache

RUN a2enmod rewrite

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html|g' \
    /etc/apache2/sites-available/000-default.conf

RUN printf '<Directory /var/www/html>\n\
    Options -Indexes +followSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
    </Directory>' > /etc/apache2/conf-available/jdb-override.conf \
    && a2enconf jdb-override


RUN echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 12M"    >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "display_errors = Off"   >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "log_errors = On"        >> /usr/local/etc/php/conf.d/custom.ini


COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads/products \
    && mkdir -p /var/www/html/uploads/profiles \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html


COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]