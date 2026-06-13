# Dockerfile
# PHP 8.3 + Apache for JDB Parts on Render.
# Document root is the project root (no /public subfolder).
# SSL CA cert for Aiven is decoded from AIVEN_CA_CERT env var at container start.

FROM php:8.3-apache

# ── System dependencies ───────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ────────────────────────────────────────────────────────────
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    gd \
    zip \
    opcache

# ── Apache config ─────────────────────────────────────────────────────────────
# Set document root to /var/www/html (project root)
# Enable mod_rewrite for URL routing
RUN a2enmod rewrite

# Allow .htaccess overrides in document root
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot /var/www/html|g' \
    /etc/apache2/sites-available/000-default.conf

# Allow AllowOverride All so .htaccess is respected
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf

# ── PHP config ────────────────────────────────────────────────────────────────
RUN echo "upload_max_filesize = 10M" >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "post_max_size = 12M"    >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "display_errors = Off"   >> /usr/local/etc/php/conf.d/custom.ini \
    && echo "log_errors = On"        >> /usr/local/etc/php/conf.d/custom.ini

# ── Copy project files ────────────────────────────────────────────────────────
COPY . /var/www/html/

# Ensure uploads folder exists with correct permissions
RUN mkdir -p /var/www/html/uploads/products \
    && mkdir -p /var/www/html/uploads/profiles \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# ── Startup script ────────────────────────────────────────────────────────────
# Decodes AIVEN_CA_CERT env var (base64) into a ca.pem file at runtime,
# then writes a env.production file from Render's environment variables.
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]