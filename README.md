# Mis Gastos - Sistema de Gestión de Gastos Personales

Sistema moderno desarrollado con Laravel 11, PHP 8.3 y PostgreSQL 16.

## Tecnologías

- **Backend**: Laravel 11.x
- **PHP**: 8.3
- **Base de Datos**: PostgreSQL 16
- **Servidor Web**: Nginx
- **Contenedores**: Docker & Docker Compose


![Laravel](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?style=for-the-badge&logo=php&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-4169E1?style=for-the-badge&logo=postgresql&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)


## Requisitos

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

### 7. Ejecutar migraciones y seeders
```bash
docker-compose exec app php artisan migrate:fresh --seed
```

### 8. Probar la aplicación
```bash
docker-compose ps
```

### 9. Correos en ambiente de pruebas
Usa la imagen oficial de Mailpit, el cual es un servidor de correo para desarrollo. Captura todos los emails enviados por la aplicación y los muestra en una interfaz web para testing.
Perfecto para probar:
- Reset de contraseñas
- Verificación de emails
- Notificaciones del sistema

## Accesos
Una vez que los contenedores estén corriendo, puedes acceder a:
| **Servicio**      | **URL**          | **Credenciales** |
|---------------|--------------|--------------|
| Aplicación      | http://localhost:8081       |    |
| PgAdmin       | http://localhost:5050    | Email: admin@misgastos.com / Password: admin |
| PostgreSQL      | http://localhost:5433    | Usuario: misgastos_user / Password: misgastos_secret |
| Dashboard de emails | http://localhost:8025 |  |

## Credenciales
Usuario Admiistrador
| **Usuario**      | **Rol**          | **Password** |
|---------------|--------------|--------------|
| admin@misgastos.com      | Administrador       | Admin123!   |

## Características Principales

###  Autenticación y Seguridad
- Registro e inicio de sesión de usuarios
- Verificación de correo electrónico
- Recuperación de contraseña
- Autenticación social con Google
- Sistema de roles y permisos granular (Spatie Permission)
- Protección de rutas por permisos
- Modo oscuro/claro global

### 👥 Gestión de Usuarios (Admin)
- CRUD completo con AJAX
- Búsqueda y filtros en tiempo real
- Paginación configurable
- Exportación de datos (CSV, Excel, PDF)
- Asignación de roles y permisos
- Validaciones en frontend y backend

###  Gestión de Roles y Permisos
- Creación y edición de roles
- Asignación dinámica de permisos
- Permisos granulares por módulo
- Interfaz intuitiva con badges visuales

### Interfaz de Usuario
- Diseño moderno y responsive (mobile-first)
- Sidebar navigation colapsable
- Top bar fija con accesos rápidos
- Componentes UI reutilizables
- Notificaciones con SweetAlert2
- Fuente Poppins (local, sin dependencias externas)
- Iconos SVG personalizados

### Sistema de Notificaciones
- Emails personalizados con Blade
- Servidor de correo para testing (Mailpit)
- Flash messages con persistencia en localStorage

## Autor
- **Carlos Adolfo Ramos Ramírez**
- **Linkedln**: https://www.linkedin.com/in/carlos-adolfo-ramos/
- **Email**: carlosramos22883@gmail.com