# ============================================================================
# DOCKERFILE - Receta para construir la imagen de PHP
# ============================================================================
# Este archivo le dice a Docker cómo crear una imagen personalizada de PHP
# con todas las extensiones y herramientas que necesitamos para Laravel.

# ----------------------------------------------------------------------------
# Línea 1: Imagen base
# ----------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

# ¿Qué hace?
# - FROM: Indica qué imagen base vamos a usar como punto de partida
# - php:8.4-fpm-alpine: 
#   * php: La imagen oficial de PHP
#   * 8.4: Versión de PHP (la más reciente)
#   * fpm: PHP-FPM (FastCGI Process Manager) - necesario para que Nginx 
#          pueda comunicarse con PHP
#   * alpine: Versión ligera de Linux (solo ~5MB vs ~100MB de Debian)
#
# Analogía: Es como decir "Voy a construir una casa empezando con estos 
# planos base"

# ----------------------------------------------------------------------------
# Línea 2-12: Instalar dependencias del sistema y extensiones de PHP
# ----------------------------------------------------------------------------
RUN apk add --no-cache \
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

# ¿Qué hace?
# - RUN: Ejecuta un comando dentro de la imagen durante la construcción
# - apk: Gestor de paquetes de Alpine Linux (como apt-get en Ubuntu)
# - add --no-cache: Instala paquetes sin guardar caché (ahorra espacio)
#
# Paquetes del sistema que instalamos:
# - postgresql-dev: Librerías necesarias para conectar PHP con PostgreSQL
# - libzip-dev: Librería para manejar archivos ZIP
# - zip, unzip: Herramientas para comprimir/descomprimir archivos
# - curl: Para hacer peticiones HTTP
# - git: Para clonar repositorios
#
# Extensiones de PHP que instalamos:
# - docker-php-ext-install: Script oficial de PHP para instalar extensiones
# - pdo_pgsql: Driver PDO para PostgreSQL (necesario para Eloquent)
# - pgsql: Driver nativo de PostgreSQL
# - zip: Extensión para manejar archivos ZIP en PHP
# - bcmath: Para cálculos matemáticos de precisión (útil para dinero)
#
# El símbolo \ permite continuar el comando en la siguiente línea
# El && ejecuta el siguiente comando solo si el anterior fue exitoso

# ----------------------------------------------------------------------------
# Línea 13-14: Instalar Composer
# ----------------------------------------------------------------------------
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ¿Qué hace?
# - COPY: Copia archivos de una imagen a otra
# - --from=composer:latest: Toma el archivo de la imagen oficial de Composer
# - /usr/bin/composer: Ruta del binario de Composer en la imagen origen
# - /usr/bin/composer: Ruta donde lo guardamos en nuestra imagen
#
# Analogía: Es como copiar un programa de una computadora a otra
# Composer es el gestor de dependencias de PHP (como npm para Node.js)

# ----------------------------------------------------------------------------
# Línea 15: Directorio de trabajo
# ----------------------------------------------------------------------------
WORKDIR /var/www

# ¿Qué hace?
# - WORKDIR: Establece el directorio donde se ejecutarán los comandos siguientes
# - /var/www: Ruta estándar para aplicaciones web en Linux
#
# Es como hacer "cd /var/www" antes de ejecutar cualquier comando

# ----------------------------------------------------------------------------
# Línea 16: Exponer puerto
# ----------------------------------------------------------------------------
EXPOSE 9000

# ¿Qué hace?
# - EXPOSE: Documenta que el contenedor escucha en el puerto 9000
# - 9000: Puerto estándar de PHP-FPM
#
# IMPORTANTE: Esto NO publica el puerto, solo lo documenta.
# Para que sea accesible desde fuera, necesitas mapearlo en docker-compose.yml
#
# Analogía: Es como poner un letrero que dice "Aquí hay una puerta"

# ----------------------------------------------------------------------------
# Línea 17: Comando por defecto
# ----------------------------------------------------------------------------
CMD ["php-fpm"]

# ¿Qué hace?
# - CMD: Comando que se ejecuta cuando el contenedor inicia
# - php-fpm: Inicia el servidor PHP-FPM
#
# Este comando mantiene el contenedor corriendo en segundo plano
# esperando peticiones de Nginx