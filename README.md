# Mis Gastos - Sistema de Gestión de Gastos Personales

Sistema moderno desarrollado con Laravel 11, PHP 8.3 y PostgreSQL 16.

## 🚀 Tecnologías

- **Backend**: Laravel 11.x
- **PHP**: 8.3
- **Base de Datos**: PostgreSQL 16
- **Servidor Web**: Nginx
- **Contenedores**: Docker & Docker Compose

## 📋 Requisitos

- Docker
- Docker Compose
- Git

## 🛠️ Instalación

### 1. Clonar el repositorio
```bash
git clone <url-del-repositorio>
cd mis-gastos
```

### 2. Copiar el archivo de entorno
```bash
cp .env.docker src/.env
```

### 3. Levantar los contenedores (esto construirá la imagen si no existe)
```bash
docker-compose up -d --build
```

### 4. Instalar las dependencias de Composer (Laravel)
```bash
docker-compose exec app composer install
```

### 5. Generar la clave de la aplicación
```bash
docker-compose exec app php artisan key:generate
```

### 6. Dar permisos correctos
```bash
docker-compose exec app chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
docker-compose exec app chmod -R 775 /var/www/storage /var/www/bootstrap/cache
```

### 7. Ejecutar migraciones
```bash
docker-compose exec app php artisan migrate:fresh --seed
```

### 8. Probar la aplicación
```bash
docker-compose ps
```

## 🌐 Accesos
Una vez que los contenedores estén corriendo, puedes acceder a:
| Servicio      | URL          | Credenciales |
|---------------|--------------|--------------|
| Aplicación      | http://localhost:5050       |    |
| PgAdmin       | Behringer    | Email: admin@misgastos.com / Password: admin |
| PostgreSQL      | localhost:5433    | Usuario: misgastos_user / Password: misgastos_secret |