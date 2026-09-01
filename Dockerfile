# syntax=docker/dockerfile:1

FROM php:8.3-cli

# UID/GID del usuario del contenedor. Se alinean con el usuario del host para que
# los archivos creados dentro del bind mount no queden como root.
ARG UID=1000
ARG GID=1000

# Extensiones de PHP requeridas por Laravel, con soporte de imagen PNG + JPEG.
# libxml2-dev no se instala: la imagen base ya trae libxml2 y las extensiones
# dom/simplexml/xml compiladas.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        curl \
        zip \
        unzip \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*

# Version fija de Composer para que los builds sean reproducibles.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Usuario sin privilegios: el contenedor no corre como root.
RUN groupadd --gid ${GID} www \
    && useradd --uid ${UID} --gid ${GID} --create-home --shell /bin/bash www

# El directorio de trabajo debe pertenecer a www ANTES de cambiar de usuario,
# de lo contrario composer no puede crear vendor/ dentro de el.
RUN mkdir -p /var/www && chown www:www /var/www
WORKDIR /var/www

# Capa de dependencias separada del codigo: cambiar un .php no reinstala vendor/.
COPY --chown=www:www composer.json composer.lock ./
USER www
RUN composer install \
        --no-interaction \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

# Codigo de la aplicacion.
COPY --chown=www:www . .
RUN composer dump-autoload --optimize

USER root
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
USER www

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
