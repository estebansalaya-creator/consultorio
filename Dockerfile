# Imagen para Railway (o cualquier servidor con Docker): PHP 8.3 + Apache
FROM php:8.3-apache

# mod_php necesita UN solo MPM (prefork). Si la imagen trae más de uno cargado,
# Apache no arranca: "AH00534: More than one MPM loaded".
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf \
 && a2enmod mpm_prefork

# Extensión de MySQL, .htaccess habilitado y puerto dinámico (Railway define $PORT)
RUN docker-php-ext-install pdo_mysql \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'date.timezone = America/Argentina/Buenos_Aires\nexpose_php = Off\nsession.cookie_httponly = 1\n' > "$PHP_INI_DIR/conf.d/app.ini"

ENV PORT=8080

# La app vive en /var/www/html/centro_medico (https://.../centro_medico/)
COPY --chown=www-data:www-data . /var/www/html/centro_medico/
RUN rm -f /var/www/html/centro_medico/Dockerfile /var/www/html/centro_medico/config.local.php \
 && printf '<?php header("Location: /centro_medico/"); exit;\n' > /var/www/html/index.php

EXPOSE 8080

# Al arrancar, vuelve a asegurar que sólo quede prefork habilitado y levanta Apache
CMD ["sh", "-c", "rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*; exec apache2-foreground"]
