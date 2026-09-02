# ============================================================================
# DOCKERFILE - Receta para construir la imagen de PHP
# ============================================================================

FROM php:8.4-fpm-alpine

# ----------------------------------------------------------------------------
# Instalar dependencias del sistema, Node.js y extensiones de PHP
# ----------------------------------------------------------------------------
RUN apk add --no-cache \
    nodejs \
    npm \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    git \
    && docker-php-ext-install \
    pdo_pgsql \
    pgsql \
    zip \
    bcmath

# Instalar extensión GD con soporte para imágenes
RUN apk add --no-cache freetype-dev libjpeg-turbo-dev libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# ----------------------------------------------------------------------------
# Instalar Composer
# ----------------------------------------------------------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ----------------------------------------------------------------------------
# Directorio de trabajo
# ----------------------------------------------------------------------------
WORKDIR /var/www

# ----------------------------------------------------------------------------
# Exponer puerto
# ----------------------------------------------------------------------------
EXPOSE 9000

# ----------------------------------------------------------------------------
# Comando por defecto
# ----------------------------------------------------------------------------
CMD ["php-fpm"]