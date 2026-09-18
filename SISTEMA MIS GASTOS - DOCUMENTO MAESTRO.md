# SISTEMA MIS GASTOS - DOCUMENTO MAESTRO  
**Última actualización:** 2026 (Fase de Catálogos y Frontend AJAX completada)  
  
---  
  
## 1. OBJETIVO DEL SISTEMA  
Sistema integral de control financiero personal y familiar que permite:  
- Llevar control de efectivo, cuentas bancarias, tarjetas de crédito y compromisos.  
- Gestionar deudores y deudas personales.  
- Dividir gastos en eventos sociales.  
- Trabajar en grupos familiares.  
- Generar reportes financieros completos.  
  
**Propósito adicional:** Servir como portafolio profesional para demostrar habilidades de desarrollo full-stack, arquitectura de software y diseño de interfaces modernas.  
  
---  
  
## 2. ARQUITECTURA TÉCNICA  
### Stack Tecnológico  
- **Backend:** Laravel 11+ (PHP 8.3+)  
- **Base de datos:** PostgreSQL 16  
- **Frontend:** Laravel Blade + Alpine.js + Tailwind CSS  
- **Bundler:** Vite  
- **Autenticación y Permisos:** Laravel Breeze + Spatie Permission  
- **UI/UX Components:** SweetAlert2, Tom Select, Croppie (recorte de imágenes), DataTables personalizados con AJAX.  
- **Almacenamiento:** Local (`storage/app/public`) con symlinks, preparado para migrar a AWS S3.  
- **Contenedores:** Docker & Docker Compose (Nginx, PHP-FPM, PostgreSQL, Mailpit).  
  
---  
  
## 3. ROLES Y PERMISOS  
### Administrador  
- Acceso total al sistema.  
- Gestión de Catálogos del Sistema (Monedas, Bancos, Tipos de Cuenta, Marcas de Red).  
- Gestión de Usuarios y Roles/Permisos.  
  
### Usuario Estándar  
- Gestión de perfil, categorías personales, efectivo, cuentas, tarjetas, compromisos, deudores, grupos y eventos.  
  
### Implementación Técnica de Permisos  
- Roles "Administrador" y "Usuario" creados por defecto vía Seeders.  
- Permisos granulares por recurso (ej: `monedas.view`, `monedas.create`, `monedas.edit`, `monedas.delete`).  
- Middleware `can:permission` en rutas.  
- Directivas `@can` y `@canany` en vistas Blade para ocultar/mostrar botones y menús.  
  
---  
  
## 4. CATÁLOGOS DEL SISTEMA (✅ COMPLETADO)  
Gestionados por el Administrador mediante modales AJAX, DataTables dinámicos y recorte de imágenes (Croppie).  
  
### 4.1 Monedas  
- **Campos:** `id`, `nombre`, `codigo` (USD, GTQ, etc.), `simbolo` ($, Q, etc.), `activo` (boolean).  
  
### 4.2 Bancos  
- **Campos:** `id`, `nombre`, `codigo` (opcional), `logo` (imagen recortada, nullable), `activo` (boolean).  
  
### 4.3 Tipos de Cuenta  
- **Campos:** `id`, `nombre` (Ahorros, Monetaria, etc.), `descripcion` (nullable), `activo` (boolean).  
  
### 4.4 Marcas de Red  
- **Campos:** `id`, `nombre` (Visa, Mastercard, etc.), `logo` (imagen recortada, nullable), `color` (código hex, nullable), `activo` (boolean).  
  
---  
  
## 5. MÓDULOS FUNCIONALES (Estado)  
### 5.1 Perfil de Usuario (✅ Parcialmente Completado)  
- Edición de nombre, email, contraseña.  
- Subida y recorte de avatar con Croppie.  
- *Pendiente:* Configuración de `moneda_preferida_id`, `fecha_corte_dia`, `zona_horaria`.  
  
### 5.2 a 5.8 (Efectivo, Compromisos, Cuentas, Tarjetas, Deudores, Grupos, Eventos)  
- ⏳ **Pendiente de desarrollo.** (Ver Sección 12).  
  
---  
  
## 12. ESTADO ACTUAL DEL DESARROLLO  
### ✅ Completado  
- [x] Autenticación (Login, Registro, Verificación, Recuperación, Google OAuth).  
- [x] Sistema de Roles y Permisos (Spatie) con Seeders.  
- [x] CRUD de Usuarios y Roles con AJAX, modales, validaciones y DataTables.  
- [x] **CRUD de Catálogos (Monedas, Bancos, Tipos de Cuenta, Marcas de Red)** con:  
  - [x] Modales dinámicos cargados vía AJAX (`fetch` + `Alpine.initTree`).  
  - [x] Recorte de imágenes para logos de Bancos y Marcas de Red (Croppie).  
  - [x] Selects avanzados con Tom Select.  
  - [x] Notificaciones de éxito/error con SweetAlert2.  
  - [x] Estados "Activo/Inactivo" con checkbox marcado por defecto.  
- [x] Layout principal responsivo (Sidebar colapsable, Top bar, Dark/Light mode).  
- [x] Componentes Blade reutilizables (`floating-input`, `data-table`, `modal`).  
- [x] Licencia de propiedad intelectual configurada en el repositorio.  
  
### 🔄 En Progreso  
- [ ] Finalizar Perfil de Usuario (campos de configuración financiera).  
- [ ] Wizard de inicio (primer login).  
  
### ⏳ Pendiente (Orden de Prioridad)  
1. Categorías de gastos (CRUD similar a catálogos, pero por usuario).  
2. Módulo de efectivo (Ingresos/Egresos con validación de saldo).  
3. Módulo de compromisos (Lógica de recurrentes y cuotas).  
4. Módulo de cuentas bancarias y tarjetas de crédito.  
5. Dashboard con gráficos y resumen financiero.  
6. Grupos familiares y Eventos (división de gastos).  
7. Reportes y Exportación (PDF, Excel).  
8. Notificaciones automáticas (Email/Push).  
9. Flujo de corte mensual.  
  
---  
  
## 13. PRÓXIMOS PASOS INMEDIATOS  
1. Agregar campos `moneda_preferida`, `fecha_corte_dia` y `zona_horaria` a la tabla `users` y al formulario de perfil.  
2. Iniciar el desarrollo del Wizard de configuración inicial para nuevos usuarios.  
  
---  
  
## 17. COMPONENTES Y HERRAMIENTAS IMPLEMENTADAS  
- **Sistema de Layout:** `app.blade.php` y `guest.blade.php` con estructura semántica.  
- **Navegación:** Sidebar con Alpine.js (`x-show`, `x-collapse`) y detección de ruta activa (`request()->routeIs()`).  
- **Formularios AJAX:** Componente `ajaxForm` que intercepta submits, muestra loading states, maneja errores 422 de Laravel y refresca tablas sin recargar la página.  
- **Tablas de Datos:** Componente `data-table` con paginación, búsqueda, ordenamiento y acciones (editar/eliminar) todo vía AJAX.  
- **Imágenes:** Integración de `Croppie` para recorte de avatares y logos antes de la subida.  
- **Selects:** `Tom Select` para búsquedas dentro de desplegables.  
- **Alertas:** `SweetAlert2` configurado globalmente con temas claro/oscuro y botones personalizados.  
- **Manejo de Errores:** Validaciones en tiempo real y mensajes de error visuales bajo cada campo.  
  
---  
  
## 18. NOTAS TÉCNICAS Y CONVENCIONES  
- **Nomenclatura:** Las rutas y permisos usan kebab-case (ej: `marcas-red.create`), las tablas usan plural snake_case (ej: `marcas_red`), y los modelos usan PascalCase singular (ej: `MarcaRed`).  
- **Imágenes:** Los logos se almacenan en `storage/app/public/logos/` y se acceden vía `asset('storage/...')`.  
- **Seguridad:** Todos los formularios incluyen `@csrf`. Las eliminaciones usan método `DELETE` con confirmación previa.  
- **Portafolio:** El código está documentado, sigue los estándares PSR-12 y utiliza las mejores prácticas de Laravel 11.  
  
---  
**FIN DEL DOCUMENTO**  
