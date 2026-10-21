# SISTEMA MIS GASTOS - DOCUMENTO MAESTRO
**Última actualización:** 17 de octubre, 2026

> Leyenda de estado: ✅ Completado · 🔄 En progreso · ⏳ Pendiente

---

## 1. OBJETIVO DEL SISTEMA

Sistema integral de control financiero personal y familiar que permite:
- Llevar control de efectivo, cuentas bancarias, tarjetas de crédito y compromisos
- Gestionar deudores y deudas personales
- Dividir gastos en eventos sociales
- Trabajar en grupos familiares
- Generar reportes financieros completos

**Propósito adicional:** Servir como portafolio profesional para demostrar habilidades de desarrollo full-stack (web + Android + iOS).

---

## 2. ARQUITECTURA TÉCNICA

### Repositorios
| Proyecto | Carpeta local | Tecnología |
|---|---|---|
| **Web + API** | `~/Proyectos/mis-gastos` (código en `src/`) | Laravel 13, PHP 8.3 |
| **Android** | `~/Proyectos/Mis Gastos Kotlin` | Kotlin + Jetpack Compose |
| **iOS** | `~/Proyectos/Mis-Gastos-iOS` | Swift + SwiftUI |

### Stack Tecnológico
- **Backend:** Laravel 13 (PHP 8.3)
- **Base de datos:** PostgreSQL 16
- **Frontend web:** Blade + Alpine.js + Tailwind CSS (Vite)
- **Autenticación:** Laravel Breeze (web) + Laravel Sanctum (API tokens para móviles) + Google OAuth (Socialite)
- **Permisos:** Spatie Laravel Permission
- **UI:** SweetAlert2, Tom Select, Croppie, tablas AJAX propias
- **Exportación:** maatwebsite/excel (CSV/XLSX) + dompdf (PDF)
- **Documentación API:** l5-swagger
- **Almacenamiento:** Local (`storage/app/public`), preparado para migrar a AWS S3
- **Notificaciones:** Laravel Notifications (email + push)
- **Generación de imágenes:** html2canvas (web) + nativo (móvil)
- **Contenedores:** Docker Compose (Nginx, PHP-FPM, PostgreSQL, pgAdmin, Mailpit)

### Principio de arquitectura (los 3 proyectos a la par)
La lógica de negocio vive en **clases de servicio** (`src/app/Services`), no en los controladores. Los controladores web y los de API usan los mismos servicios, así una regla (por ejemplo, "no gastar más efectivo del que hay") existe en un solo lugar.

Flujo por cada módulo: **Servicio + tests → Web → Endpoint API → Android → iOS**.

---

## 3. ROLES Y PERMISOS ✅

### Administrador
**Catálogos que puede gestionar:** Monedas, Bancos, Tipos de Cuenta, Marcas de Red. También gestiona usuarios y roles.

### Usuario
**Puede gestionar:** Perfil, categorías personales, efectivo, cuentas bancarias, tarjetas de crédito, compromisos, deudores, grupos familiares y eventos.

### Implementación
- Roles "Administrador" y "Usuario" creados por seeders.
- Permisos granulares por recurso (`compromisos.view`, `compromisos.create`, `compromisos.edit`, `compromisos.delete`, `compromisos.export`).
- Middleware `can:permiso` en rutas; `@can` / `@canany` en vistas.

---

## 4. CATÁLOGOS DEL SISTEMA

### 4.1 Monedas ✅ (Solo Administrador)
**Campos:** id, código (USD, GTQ…), nombre, símbolo ($, Q…), activo

**Comportamiento:**
- Cada usuario selecciona su moneda preferida y puede cambiarla desde su perfil
- Los registros anteriores mantienen la moneda con la que fueron creados (⏳ pendiente: guardar `moneda_id` en cada movimiento)

### 4.2 Bancos ✅ (Solo Administrador)
**Campos:** id, nombre, código (opcional), tipo de emisor (catálogo 4.6), logo (opcional, recortado con Croppie), activo

### 4.3 Tipos de Cuenta ✅ (Solo Administrador)
**Campos:** id, nombre (Ahorros, Monetaria…), descripción, activo

### 4.4 Marcas de Red ✅ (Solo Administrador)
**Campos:** id, nombre (Visa, Mastercard, Amex…), logo, color corporativo, activo
> Nota técnica: la tabla se llama `marca_reds` (no `marcas_red` como dice la convención).

### 4.6 Catálogos de tipos ✅ (Solo Administrador, 20/10/2026)
Un solo CRUD genérico (`Admin\CatalogoGenericoController` + `App\Support\CatalogosAdmin`), en Configuración → Catálogos. Cada uno tiene nombre, descripción, orden y activo; un elemento en uso no se elimina (se desactiva) y los inactivos ya no se pueden elegir en los formularios.

| Catálogo | Tabla | Lo usa | Valores iniciales |
|---|---|---|---|
| Tipos de contacto | `tipos_contacto` | `contactos.tipo_contacto_id` | Persona, Tienda, Empresa, Institución, Mascota |
| Tipos de emisor | `tipos_emisor` | `bancos.tipo_emisor_id` | Banco, Tienda, Financiera, Cooperativa |
| Tipos de préstamo | `tipos_prestamo` | `prestamos.tipo_prestamo_id` | Consumo / personal, Hipotecario, Vehículo, De una persona, Otro |
| Tipos de ingreso | `tipos_ingreso` | `fuentes_ingreso.tipo_ingreso_id` | Sueldo (planilla), Servicios profesionales, Otro |
| Conceptos de planilla | `conceptos_planilla` | atajos del formulario de ingresos | ISSS (3 %, tope $30), AFP (7.25 %), Renta, Comisión por transferencia, Bono |

Los conceptos de planilla tienen además tipo (descuento/adición), porcentaje, tope y monto fijo.

**Se quedan fijos en el código** (cambiarlos rompería cálculos): ingreso/egreso, tipos de movimiento de tarjeta (compra, pago, avance…), estado y frecuencia de compromisos, medio de pago, origen/destino de transferencias.

**Agregar otro catálogo:** migración + modelo que extienda `Catalogos\Catalogo` + una entrada en `CatalogosAdmin::DEFINICIONES` + sus permisos (`modulo.view/create/edit/delete`) en el seeder y en `Permisos::MODULOS`. La API los entrega en `GET /api/catalogos`.

### 4.5 Categorías de Gastos 🔄
**Estado actual:** las categorías personales están completas (CRUD por usuario, color, tipo ingreso/egreso, filtros, exportación).

**Pendiente:**
- ⏳ Categorías del sistema por defecto (no editables ni eliminables):
  Vivienda, Servicios, Alimentación, Transporte, Salud, Ocio y Entretenimiento, Educación, Ropa, Cuidado Personal, Ahorro, Deuda, Seguro, Super Mercado
- ⏳ Campo `icono` y catálogo predefinido de iconos para elegir
- ⏳ Marcar categorías del sistema con un campo (`es_sistema`) en lugar de identificarlas por nombre. Hoy "Saldo inicial" se busca por nombre (constante `CicloEfectivoService::CATEGORIA_SALDO_INICIAL`).

**Comportamiento:**
- Los usuarios pueden crear categorías personalizadas
- Solo el creador puede ver y editar sus categorías personalizadas
- Las categorías del sistema no se pueden editar ni eliminar

---

## 5. MÓDULOS FUNCIONALES

### 5.1 PERFIL DE USUARIO ✅ (web)
- Editar nombre, email y contraseña
- Subir/recortar foto de perfil (Croppie)
- Moneda preferida, día de corte (1-31), zona horaria
- Eliminar cuenta

**Pendiente:**
- ⏳ Teléfono y fecha de nacimiento (opcionales)
- ⏳ Configuración de horarios de notificaciones (JSON)
- ⏳ Exportar todos mis datos

### 5.2 EFECTIVO ✅

#### 5.2.1 Ingresos de Efectivo
**Campos:** id, descripción, monto, categoría, fecha, usuario, `es_saldo_arrastrado`
- Se suman al "Efectivo actual"
- ⏳ Pendiente: comentario; ingresos recurrentes automáticos (ej. sueldo en efectivo)

#### 5.2.2 Egresos de Efectivo
**Campos:** id, descripción, monto, categoría, fecha, usuario, compromiso_id (si paga un compromiso)
- **VALIDACIÓN CRÍTICA:** el egreso no puede ser mayor al "Efectivo actual"; se muestra alerta y se bloquea
- Tampoco se puede editar ni eliminar un ingreso si el efectivo quedaría en negativo
- ⏳ Pendiente: comentario

#### 5.2.3 Cálculo de Efectivo Actual
```
Efectivo Actual = Suma(Ingresos) - Suma(Egresos)   (excluyendo saldos arrastrados)
```
Implementado en `CicloEfectivoService::balance()`.

#### 5.2.4 Saldo inicial y saldo arrastrado
- **Saldo inicial:** el efectivo registrado en el wizard. Es dinero real y cuenta en el balance.
- **Saldo arrastrado:** en cada corte se crea un ingreso "Saldo inicial" con `es_saldo_arrastrado = true` y el efectivo al cierre. **Es informativo**: muestra con cuánto abre el ciclo, pero no se suma al balance (si se sumara, el dinero se contaría dos veces). No se puede editar ni eliminar.

### 5.3 COMPROMISOS 🔄

**Modelo implementado (padre → hijos):**
- **`compromisos_recurrentes`** (la regla, no se paga): categoría, descripción, comentario, monto, medio de pago, frecuencia, `dia_semana` (semanal), `dia_pago` / `dia_pago_2` (mensual / quincenal; **31 = último día del mes**), `fecha_inicio`, `fecha_fin` o `total_cuotas`, `cuotas_generadas`, `generado_hasta`, `activo`.
- **`compromisos`** (cada pago concreto): `compromiso_recurrente_id` (null si es de una sola vez), categoría, descripción, comentario, `monto`, `saldo_pendiente`, `fecha_esperada` (opcional), `ciclo_inicio` (ciclo al que pertenece), `numero_cuota`, `total_cuotas`, medio de pago, `estado` (`pendiente`, `pagado`, `cancelado`).
- Lógica en `CompromisoService`: `crearUnico`, `crearRecurrente`, `sincronizar` (genera los pagos hasta el fin del ciclo; es idempotente y corre al abrir Compromisos, en el corte y en el comando diario), `aplicarAbono`, `cancelar`.

**Implementado ✅:**
- Una sola vez (con o sin fecha; si la fecha cae en otro ciclo, pertenece a ese ciclo) y recurrentes semanal, quincenal (dos días fijos) y mensual
- Fin indefinido, en una fecha o tras N cuotas; la serie se desactiva sola al terminar
- Estados visuales con color: Pendiente, Parcial, Vencido, Antiguo, Pagado, Cancelado (`Compromiso::estadoVisual()` y el scope del mismo nombre)
- Lo no pagado de ciclos anteriores aparece como **Antiguo** en el ciclo actual (no se copia)
- Filtros por período (ciclo actual / próximos / todos) y estado
- Pago total o parcial con efectivo desde la lista o el detalle; no permite pagar más que el pendiente
- Editar o eliminar el egreso de un pago recalcula el saldo del compromiso
- Detalle con historial de abonos
- Editar un pago no cambia el resto de la serie
- Cancelar "solo este pago" o "este y los siguientes" (los pagados o con abonos se conservan)
- Categoría opcional (será obligatoria cuando existan las categorías del sistema)

**Pendiente ⏳:**
- Editar la regla de una serie (por ejemplo, subir el monto de los meses siguientes); hoy se cancela y se crea otra
- Pagar con cuenta bancaria o tarjeta (cuando existan esos módulos)
- Préstamos y mora de tarjeta
- Endpoints de API y pantallas móviles

**Tipos de compromisos:**

#### A. Una sola vez
Ejemplo: "Pagar préstamo a David". Puede no tener fecha (se paga "en algún momento") o tener fecha estimada; si la fecha cae en otro ciclo, pertenece a ese ciclo.

#### B. Recurrentes (padre → hijos)
- Ejemplo: "Cuota de Gaby", $150 mensual hasta que cumpla 18; "Microbús de Gaby", $37.50 hasta el último mes de colegio
- Al crearlo se genera el compromiso del ciclo actual; en cada corte se genera el del siguiente
- Puede tener fecha fin o ser indefinido
- Se pueden cancelar los futuros sin tocar los ya generados

#### C. Por cuotas
Ejemplo: $1,200 en 12 cuotas de $100. Se define el total de cuotas y por cuál va. Cada cuota es un compromiso.

#### D. Compras de tarjeta en cuotas (tasa cero)
1. Se descuenta el TOTAL del disponible de la tarjeta inmediatamente
2. Se generan compromisos mensuales por cada cuota, en la fecha de corte de la tarjeta
3. El usuario ve la proyección de cuotas al crear la compra
4. La última cuota absorbe el ajuste de centavos

Ejemplo: disponible $1,000, compra TV $600 en 6 cuotas → disponible $400 y 6 compromisos de $100.

#### E. Préstamos
**Campos adicionales:** monto_total, tasa_interes (opcional), saldo_pendiente, numero_cuotas, cuota_mensual, banco_id, fecha_inicio, fecha_fin estimada
**Funcionalidades:** registrar abonos, cuotas pagadas vs pendientes, calcular intereses si se conoce la tasa.

**Estados de compromiso:**
- **Registrado/Pendiente:** fecha de pago futura o sin fecha
- **Parcial:** pagado parcialmente (valor_pendiente < valor_total)
- **Vencido:** fecha de pago pasada y no pagado (color de alerta)
- **Pagado:** completamente pagado
- **Antiguo:** de ciclos anteriores y aún sin pagar (se muestra diferente)

**Pagos parciales:** un compromiso de $15 se puede pagar en abonos ($10 + $5, o 15 de $1). Se muestra el valor original y el pendiente.

**Mora de tarjeta de crédito:** si vence el compromiso de un estado de cuenta, el usuario puede crear un compromiso "Mora de tarjeta de crédito" (descripción editable, valor manual) relacionado con el compromiso vencido.

**Al pagarse**, el compromiso se convierte en egreso de efectivo, de cuenta bancaria o de tarjeta.

### 5.4 CUENTAS BANCARIAS Y BILLETERAS ✅ (web)

**Tablas:** `cuentas` (nombre, banco opcional, tipo de cuenta, número **encriptado**, color, saldo inicial, fecha inicial, activo), `movimientos_cuenta` y `transferencias`.
**Lógica:** `CuentaService` y `TransferenciaService`.

- Tipos de cuenta incluyen **"Billetera digital"** (Chivo, Niu…)
- **Saldo** = saldo inicial + ingresos − egresos; nunca puede quedar en negativo
- **Corte:** el "saldo al iniciar el ciclo" y el "saldo al corte" se calculan al vuelo (sin filas de arrastre)
- Movimientos propios (ingreso/egreso con categoría), editables solo en el ciclo actual
- **Transferencias** entre efectivo y cuentas ("Metí al banco", "Saqué de la Chivo", de cuenta a cuenta): un registro crea la salida y la entrada; se eliminan juntas (si el destino no queda en negativo)
- **Pagar compromisos** desde efectivo o desde cualquier cuenta con un solo botón "Pagar"; el historial de abonos muestra el origen
- Una cuenta con movimientos no se elimina: se desactiva
- Exportación de movimientos (CSV, Excel, PDF)
- ⏳ API para móviles, transferencias desde tarjeta (avance) — con el módulo de tarjetas

### 5.5 TARJETAS DE CRÉDITO ✅ (web)

**Tablas:** `tarjetas_credito` (nombre, banco, marca, últimos 4 dígitos, color, límite, tasa, día de corte y de pago — 31 = último día —, `deuda_inicial`, fecha de registro), `movimientos_tarjeta`, `estados_cuenta_tarjeta`, `estado_cuenta_items`. Los compromisos se ligan con `tarjeta_credito_id` y `estado_cuenta_tarjeta_id`.
**Lógica:** `TarjetaService`.

- **Deuda** = estado de cuenta pendiente al registrarla + compras, avances y cargos − créditos − lo pagado en sus compromisos. **Disponible** = límite − deuda (se recupera solo al pagar)
- **Registro:** se pide el disponible de hoy y, si existe, el estado de cuenta pendiente (monto y fecha límite) → compromiso. Lo usado sin facturar entra en el próximo corte
- **Movimientos:** compra (de contado o **en cuotas a tasa cero**: descuenta el total y factura una cuota por corte; la última absorbe los centavos), cargo (intereses, seguro, comisión), crédito (devolución o promoción). Solo se editan mientras no se facturen
- **Avance** (tarjeta → Chivo/efectivo): transferencia con la tarjeta como origen
- **Corte automático:** genera el estado de cuenta con su detalle y un **compromiso de pago** con la fecha límite (editable por estado de cuenta; el compromiso la sigue). Corre al abrir tarjetas o compromisos y en el comando diario
- **Mora:** en un estado vencido, "Registrar mora" crea un cargo y su propio compromiso (no entra en el siguiente corte)
- **Pagar** un estado de cuenta (completo o un abono) desde efectivo, una cuenta **u otra tarjeta** (ej. la Credisiman con la Atlántida vía NPE) **sin categoría** (no es un gasto nuevo: cada compra ya tiene la suya). Con tarjeta queda como una compra en la tarjeta que paga (`movimientos_tarjeta.pago_compromiso_id`); editarla o eliminarla ajusta el abono. Una tarjeta no paga su propio estado. Lógica compartida en `PagoCompromisoService` (web y `POST /api/compromisos/{id}/pagar`, opciones en `GET /api/compromisos/{id}/origenes-pago`)
- Una tarjeta con historial no se elimina: se desactiva
- ⏳ API para móviles; foto de la tarjeta (opcional en la especificación)

### 5.6 CONTACTOS Y "NOS DEBEN" ✅ (web + API)

**Tablas:** `contactos` (antes `deudores`: nombre, apellido, tipo persona/tienda/empresa/institución, teléfono, correo, **foto privada**, notas, activo), `deudas` (`contacto_id`, descripción, monto, fecha, origen y el movimiento que generó) y `deuda_pagos`. Préstamos, compromisos (y sus reglas) y movimientos de efectivo, cuenta y tarjeta tienen `contacto_id` opcional ("¿con quién?").
**Lógica:** `ContactoService` (contactos, foto, "¿con quién?", ficha) y `DeudorService` (deudas y pagos).

- **Ficha del contacto:** lo que te debe (deudas y pagos), lo que le debes (préstamos), sus compromisos y todos los movimientos con él; le pagaste / te pagó en el año
- **"¿Con quién?"** en efectivo, cuentas, tarjetas, compromisos y préstamos: selector con búsqueda y fotos; al **escribir un nombre nuevo** se crea el contacto al guardar (no se duplica si ya existe con ese nombre). Se ve como una ficha pequeña en las listas. El contacto de un compromiso pasa a los pagos generados y a su egreso; el de un préstamo, a sus pagos
- **Foto** elegida y recortada con el recortador (Croppie, circular, 512 px) y guardada en el disco privado (`local`), servida solo a miembros del hogar (`contactos.foto` en web, `api.contactos.foto` con token)
- **Nos deben:** vista filtrada de contactos con deudas. Prestar desde efectivo, cuenta o tarjeta ("Préstamos a terceros") o solo anotar; un pago se reparte de la deuda más antigua a la más reciente; lo pendiente se calcula de los pagos. Los movimientos de una deuda se gestionan desde el contacto (`MovimientoVinculado`)
- Un contacto con historial no se elimina: se desactiva
- Permisos `contactos.view/create/edit/delete` (contactos) y `deudores.view/create/edit/delete` (deudas y pagos). Menú "Contactos" y "Nos deben"
- ⏳ Pantallas móviles

### 5.6b PRÉSTAMOS (lo que debemos) ✅ (web + API)

**Tablas:** `prestamos` (titular, tipo, banco o acreedor, monto original, tasa anual, tasa de mora, plazo, otorgamiento, día de pago, cuota capital+interés, **saldo de capital a una fecha**, medio de pago, compromiso mensual, color), `prestamo_cargos` (seguros, IVA… de cada cuota) y `prestamo_pagos` (capital, interés, cargos, mora, origen, egreso y abono al compromiso).
**Lógica:** `PrestamoService`.

- **Saldo** = saldo a la fecha de registro − capital de los pagos. **Interés** = saldo × tasa × días / 365 (como los bancos de El Salvador; verificado: pago real 117.46 de interés, "saldo de intereses" de las apps 423.22 y 93.38, y "deuda total" 17,540.20)
- **Pago con desglose** sugerido a la fecha (ajustable a lo que diga el banco); para abonar extra se sube el capital. Desde efectivo o cuenta crea el egreso (categoría "Préstamos y deudas") y abona la cuota del mes en Compromisos; por planilla o "solo anotar" no mueve dinero. Solo se elimina el último pago (revierte egreso y compromiso)
- **Pago mixto:** `monto_planilla` = parte de la cuota que se descuenta del sueldo (ej. casa: 526.50 por planilla + 316.43 en ventanilla = 842.93). El compromiso mensual y el egreso son solo el resto; el pago guarda la parte de planilla
- Al registrarlo (si no es todo por planilla) puede crear el **compromiso mensual** de la cuota; "Pagar" ese compromiso abre el formulario del préstamo
- **Proyecciones:** tabla de pagos restante, fecha de fin, intereses por pagar; **qué pasa si…** pago todo hoy (monto y ahorro), abono extra mensual (meses menos y ahorro), abono único hoy reduciendo plazo o bajando la cuota; préstamos sin cuota fija (ej. de una persona) se proyectan con "lo que puedo pagar al mes"
- Los egresos de un préstamo se gestionan desde Préstamos (`App\Support\MovimientoVinculado`)
- Permisos `prestamos.view/create/edit/delete`
- ⏳ Registro automático de los descuentos de planilla (con el módulo de ingresos con desglose); comisión por pago anticipado; seguros que bajan con el saldo

### 5.6c INGRESOS CON DESGLOSE ✅ (web + API)

**Tablas:** `fuentes_ingreso` (quién lo recibe, tipo planilla/servicios/otro, pagador, frecuencia mensual/quincenal, días de pago, bruto habitual, destino del neto), `fuente_ingreso_conceptos` (plantilla: descuento o adición, monto fijo o % del bruto con tope, préstamo ligado), `ingresos` (cada pago recibido: bruto, neto, destino y su movimiento) e `ingreso_lineas`.
**Lógica:** `IngresoService`.

- **Plantilla** con atajos: ISSS 3 % (tope $30), AFP 7.25 %, renta (monto), comisión por transferencia, adiciones (bono). Ej. sueldo de Stef: 1,920 − 231.16 − 139.20 − 30 − 350.24 − 526.50 = **642.90**
- **Registrar pago recibido:** propone las líneas (recalcula los % al cambiar el bruto), neto en vivo; el **neto** entra como ingreso ("Sueldo y trabajos") al efectivo o a una cuenta (o solo se anota)
- Un descuento ligado a un préstamo **100 % de planilla** registra su pago en Préstamos (cargos, interés del período y el resto a capital). Si el préstamo es mixto (casa), su pago se registra al pagar la ventanilla
- **Resumen anual** por fuente: bruto, cada descuento y adición, neto (para la declaración de renta)
- El neto se gestiona desde Ingresos (`MovimientoVinculado`); eliminar un pago revierte su ingreso y los pagos de préstamos que registró
- Permisos `ingresos.view/create/edit/delete`. Menú "Ingresos"
- ⏳ Recordatorio el día de pago; el historial de sueldos de la hoja quedó como movimientos de cuenta

### 5.7 HOGAR (GRUPOS FAMILIARES) ✅ (web + API)

**Tablas:** `hogares` (nombre, administrador), `users.hogar_id`, `hogar_invitaciones` (correo, invitado por, estado pendiente/aceptada/rechazada/cancelada). Las 9 tablas con datos (`categorias_personales`, `movimientos_efectivo`, `compromisos_recurrentes`, `compromisos`, `cuentas`, `transferencias`, `movimientos_cuenta`, `tarjetas_credito`, `movimientos_tarjeta`) llevan `hogar_id`.
**Lógica:** `HogarService`; trait `PerteneceAHogar` en los modelos (asigna `hogar_id` al crear, scope `delHogar`, `esDelHogar()`).

- **Un solo fondo:** los miembros comparten efectivo, ciclo, categorías, compromisos, cuentas y tarjetas. Nada es privado
- Todo usuario tiene un hogar (se crea al registrarse); quien vive solo no nota nada
- `user_id` = **quién lo hizo** (por defecto quien registra; se puede cambiar a otro miembro) o el **titular** en cuentas y tarjetas. El selector aparece solo si el hogar tiene más de un miembro; las listas muestran las iniciales
- **Ciclo del hogar:** el día de corte y la zona horaria se mantienen iguales en todos los miembros (cambiarlo en un perfil lo cambia para todos)
- **Flujo:** quien administra invita por correo (notificación + email; si no tiene cuenta, se registra con ese correo) → el invitado acepta o rechaza en la pantalla Hogar
- Al aceptar siendo el **único miembro** de su hogar, sus registros se suman al nuevo (categorías con el mismo nombre se unifican; los saldos arrastrados se recalculan). Si su hogar tenía más miembros, sus registros se quedan allí
- **Salir / quitar:** los registros se quedan en el hogar; la persona arranca un hogar propio vacío. Si sale quien administra, la administración pasa al siguiente miembro
- Al eliminar un usuario, sus registros pasan a otro miembro; si era el único, el hogar se elimina con él
- Permisos `hogar.view` (pantalla, aceptar, salir) y `hogar.edit` (renombrar, invitar, quitar; además hay que administrar el hogar)
- ⏳ Pantallas de Android e iOS

### 5.8 EVENTOS (DIVISIÓN DE GASTOS) ⏳

**Evento:** id, nombre ("Cumpleaños de María"), fecha, lugar, usuario_id, propina_porcentaje (opcional), quién pagó, tipo_pago, referencia al pago
**Participante:** id, evento_id, nombre, es_festejado, total_a_pagar, ya_pago, fecha_pago, tipo_pago
**Ítem consumido:** id, evento_id, participante(s), descripción, valor, compartido

**Lógica de cálculo:**
- Sin festejados: cada persona paga sus consumos + su parte proporcional de propina
- Con festejados: el consumo de los festejados (con su propina) se divide entre los no festejados, que además pagan sus propios consumos y su propina

**Funcionalidades:**
- Registrar participantes, consumos, festejados y propina
- Si yo pagué: registrar el gasto (efectivo/tarjeta/cuenta) y los ingresos a medida que me pagan
- Si otro pagó: solo marcar quién ya pagó
- Generar una **imagen** de la tabla de totales para compartir por WhatsApp
- Editar si hubo errores

---

### Logos y colores
- **Bancos y emisores** (catálogo `bancos` con `tipo`: banco, tienda, financiera, cooperativa; ej. Credisiman = tienda) y marcas de red tienen logo en su catálogo (Configuración).
- El texto sobre un color (tarjetas, categorías, préstamos) se elige por **contraste WCAG** (`App\Support\Color::textoSobre`, casi negro o blanco, el que más se lea; con degradado, el que mejor se lea en ambos extremos). El degradado de las tarjetas es opaco (`Color::oscurecer`) para verse igual en tema claro y oscuro. Componentes `x-logo-banco` (sin logo: iniciales) y `x-logo-red` (pastilla blanca sobre el color de la tarjeta); se muestran en tarjetas, cuentas y préstamos

## 6. DASHBOARD ✅ (web + `GET /api/dashboard`)

**Lógica:** `DashboardService`.

- **Ciclo actual** (inicio, corte, días que faltan) y vínculos rápidos
- **Alertas** (con ícono y etiqueta, ordenadas por gravedad): compromisos vencidos, que vencen hoy o en 3 días, tarjetas sobre su límite, día de pago de un ingreso aún no registrado
- **Indicadores:** efectivo · en cuentas · por pagar este ciclo · **al pagar mis compromisos tendré** (efectivo + cuentas − compromisos hasta el corte) · disponible en tarjetas y **"de las que sí puedo sacar"** (solo tarjetas que sirven para avances, sin restar sobregiros) · deuda en tarjetas · préstamos (saldo y cuotas al mes) · nos deben
- **Cuadro de tarjetas** como en la hoja: logo, corte, día de pago, límite, disponible, por pagar y su fecha, con totales
- **Gráficos** (HTML/CSS, paleta validada con el validador de la guía de visualización en claro y oscuro): gastos del ciclo por categoría (barras, las 8 mayores + "Otras") e ingresos vs gastos de los últimos 6 ciclos (columnas, leyenda, detalle al pasar el cursor y vista de tabla)
- **Qué es gasto:** egresos con categoría (efectivo y cuentas, sin transferencias) + compras y cargos de tarjeta; no cuentan movimientos entre cuentas, ajustes, préstamos a terceros ni pago de avances. **Qué es ingreso:** ingresos con categoría sin transferencias; no cuentan saldo inicial, avances de tarjeta, préstamos recibidos, cobros de deudas, movimientos entre cuentas ni ajustes
- Próximos compromisos y cuentas con su saldo
- Tarjetas: campo **"sirve para avances o recargas"** (las de tienda no); banco y red son opcionales

## 7. REPORTES ⏳

### 7.1 Ejecución Financiera
Ingresos de efectivo, egresos de efectivo, compromisos, tarjetas (ordenadas por fecha límite de pago), cuentas bancarias, deudores. Totales grandes al final. Filtro: ciclo actual o rango.

### 7.2 Gastos por Categoría
Ciclo actual o rango de meses.

### 7.3 Estado de Cuenta (por tarjeta y mes)

### 7.4 Préstamos
Datos del préstamo, cuotas pagadas y faltantes, totales.

### 7.5 Exportación
PDF, CSV, XLSX e imagen.

---

## 8. NOTIFICACIONES 🔄

**Implementado:** tabla `notifications`, página de notificaciones, recordatorio diario (8:00) de compromisos vencidos (máximo 5 por compromiso).

### 8.1 Tipos
1. Compromisos con fecha de pago hoy
2. Tarjetas con fecha límite cercana (3 días antes) y del día
3. Compromisos vencidos
4. Invitaciones a grupos familiares
5. Recordatorios de deudores

### 8.2 Canales
- **Email** con el detalle del compromiso
- **Push** en móviles

### 8.3 Horarios (por usuario)
8:00 AM, 10:00 AM, 12:00 PM, 2:00 PM, 4:00 PM, activables uno a uno, según la zona horaria del usuario.

---

## 9. WIZARD DE INICIO (PRIMER LOGIN) 🔄

**Implementado (web):** perfil con foto, configuración de cuenta, configuración financiera (moneda, día de corte, zona horaria), efectivo inicial, compromisos múltiples, borrador guardado automáticamente.

**Pasos objetivo:**
1. ✅ Configuración básica (moneda, día de corte, zona horaria)
2. ✅ Efectivo inicial ("así se registran los ingresos")
3. ⏳ Cuentas bancarias (opcional)
4. ⏳ Tarjetas de crédito (opcional, con compromiso automático si hay saldo pendiente)
5. ⏳ Deudas existentes / préstamos (opcional)
6. ⏳ Deudores (opcional)
7. ✅ Compromisos activos (opcional)
8. ⏳ Grupo familiar (opcional)
9. ⏳ Notificaciones
10. ⏳ Resumen y finalizar

---

## 10. FLUJO DE CORTE MENSUAL 🔄

**¿Cuándo?** En el día de corte del usuario. Si es 31 (o 29/30) y el mes es más corto, el último día del mes.
**Implementado en:** `CicloEfectivoService::closeIfNeeded()`, que corre cada día a las 00:01 y también al entrar a Efectivo. Si el usuario estuvo ausente varios meses, cierra cada ciclo pendiente.

### 10.1 Efectivo ✅
1. Se calcula el efectivo al cierre del ciclo
2. Se registra como saldo arrastrado del siguiente ciclo (`es_saldo_arrastrado = true`)
3. Los ingresos recurrentes (sueldo) se registran aparte (⏳)

### 10.2 Cuentas bancarias ⏳
Se guarda el monto final de cada cuenta como inicial del siguiente ciclo.

### 10.3 Tarjetas de crédito ⏳
Se genera el estado de cuenta y su compromiso.

### 10.4 Compromisos 🔄
- Los pendientes pasan al siguiente ciclo conservando su fecha (a menos que el usuario la cambie)
- Los recurrentes generan el compromiso del siguiente ciclo (✅ básico)

### 10.5 Histórico ⏳
Registro de cada corte: fecha, efectivo, monto de cada cuenta, disponible de cada tarjeta, compromisos pendientes.

---

## 11. SEGURIDAD Y PRIVACIDAD

- **Encriptar:** números de tarjeta y de cuenta (⏳)
- **Contraseñas:** bcrypt ✅
- **CSRF** en formularios web ✅; validación en frontend y backend ✅
- **Aislamiento por usuario:** cada consulta filtra por `user_id`; acceder a un registro ajeno devuelve 404 ✅
- **Backups** diarios con 30 días de retención (⏳, al publicar)
- **Exportar mis datos** y **eliminar cuenta** con confirmación (eliminar ✅, exportar ⏳)

---

## 12. ESTADO ACTUAL DEL DESARROLLO

### Web (Laravel)
| Módulo | Estado |
|---|---|
| Autenticación, registro, verificación, recuperación, Google | ✅ |
| Roles y permisos; CRUD de usuarios y roles | ✅ |
| Catálogos (Monedas, Bancos, Tipos de Cuenta, Marcas de Red, tipos de contacto/emisor/préstamo/ingreso, conceptos de planilla) | ✅ |
| Perfil | ✅ |
| Categorías personales | ✅ (faltan categorías del sistema e iconos) |
| Efectivo | ✅ |
| Wizard | 🔄 |
| Compromisos | ✅ web (pagar desde efectivo, cuenta u otra tarjeta; falta editar la regla de una serie) |
| Tarjetas de crédito (cortes, cuotas, avances, mora) | ✅ |
| Cuentas bancarias y billeteras, transferencias, pagar compromisos desde cuenta | ✅ |
| Hogar: miembros, invitaciones, quién lo hizo, titulares | ✅ |
| Contactos (foto privada, "¿con quién?") y "Nos deben" | ✅ |
| Préstamos: pagos con desglose, proyecciones y simulador | ✅ |
| Ingresos con desglose (sueldos, servicios profesionales) | ✅ |
| Dashboard (inicio) e intereses de tarjeta | ✅ |
| Eventos, Reportes | ⏳ |

### API (para móviles)
| Endpoint | Estado |
|---|---|
| Auth (registro, login, Google, olvido/reset, logout, usuario) | ✅ |
| Perfil y avatar | ✅ |
| Admin usuarios y roles | ✅ |
| `GET /api/catalogos` (monedas, bancos, tipos de cuenta, marcas de red, tipos de contacto/emisor/préstamo/ingreso y conceptos de planilla activos) | ✅ |
| `GET /api/compromisos/{id}/origenes-pago`, `POST /api/compromisos/{id}/pagar` (efectivo, cuenta o tarjeta) | ✅ |
| `/api/categorias` (listar, crear, editar, eliminar si no está en uso) | ✅ |
| `/api/efectivo` (por ciclo con resumen y navegación; crear, editar, eliminar; pagar compromisos con `compromiso_id`) | ✅ |
| `/api/compromisos` (listar por período/estado, detalle con abonos, crear único o recurrente, editar pago, cancelar pago o serie) | ✅ |
| `/api/hogar` (ver, renombrar, invitar, cancelar, aceptar, rechazar, salir, quitar miembro); `user_id` en efectivo y compromisos | ✅ |
| `/api/contactos` (CRUD, `?filtro=deben`, detalle con deudas y pagos, `GET /api/contactos/{id}/foto`), `POST /api/contactos/{id}/deudas`, `POST /api/contactos/{id}/pagos`, `DELETE /api/deudas/{id}`, `DELETE /api/deuda-pagos/{id}`; `contacto` en efectivo y compromisos | ✅ |
| `/api/prestamos` (CRUD, detalle con pagos y desglose sugerido, `datos` con lo guardado para editar), `GET /api/prestamos/{id}/proyeccion`, `POST /api/prestamos/{id}/pagos`, `DELETE /api/prestamo-pagos/{id}` | ✅ |
| `/api/ingresos` (fuentes CRUD, detalle con pagos), `GET /api/ingresos/{id}/propuesta`, `POST /api/ingresos/{id}/recibos`, `DELETE /api/recibos/{id}` | ✅ |
| `GET /api/dashboard` (resumen de inicio) | ✅ |
| `/api/cuentas` (lista con saldo, detalle por ciclo con resumen y movimientos, CRUD), `POST/PUT/DELETE /api/cuentas/{id}/movimientos/{mov}` | ✅ |
| `GET /api/transferencias/opciones`, `POST /api/transferencias` (origen tarjeta = avance), `DELETE /api/transferencias/{id}` | ✅ |
| `/api/tarjetas` (lista con deuda, disponible, próximo corte y pago; detalle con lo que entra al corte, cuotas, estados de cuenta y costo de no pagar; CRUD), `POST/PUT/DELETE /api/tarjetas/{id}/movimientos/{mov}` | ✅ |
| Documentación Swagger: `/api/documentation` | ✅ |

### Android (Kotlin)
| Pantalla | Estado |
|---|---|
| Login, registro, Google, olvido/reset de contraseña; la sesión se restaura sola y un 401 regresa al login | ✅ |
| Navegación inferior: Inicio · Efectivo · Tarjetas · Compromisos · Más | ✅ |
| Inicio (`/api/dashboard`): disponible real, avisos, próximos pagos, tarjetas, cuentas con logo, deudas, gastos por categoría, historial | ✅ |
| Efectivo: ciclos anterior/siguiente, resumen, filtro, registrar/editar/eliminar con categoría, "¿quién?" y "¿con quién?" | ✅ |
| Compromisos: filtros por período y estado, detalle, pagar desde efectivo/cuenta/otra tarjeta, cancelar pago o serie | ✅ |
| Cuentas: lista con logo y saldo, detalle por ciclo, registrar/editar/eliminar movimientos, eliminar transferencias | ✅ |
| Transferencias desde Efectivo, Cuentas y Tarjetas (avance) | ✅ |
| Tarjetas: lista con su color, detalle (por pagar con costo de no pagar, próximo corte, sin facturar, cuotas, estados de cuenta), compras en cuotas, cargos y créditos | ✅ |
| Contactos: lista con búsqueda y "nos deben", foto privada, crear/editar, prestar o anotar deudas, registrar y eliminar cobros | ✅ |
| Préstamos: lista con saldo y avance, detalle con cuota y cargos, registrar pago con la sugerencia del sistema, eliminar el último, simulador (pagar hoy, extra mensual, abono único) | ✅ |
| Ingresos: fuentes con su plantilla, registrar pago recibido con desglose calculado y editable (los préstamos de planilla se pagan solos), eliminar recibos | ✅ |
| Más: usuario, hogar y miembros, acceso a cuentas, contactos, préstamos e ingresos, cerrar sesión | ✅ |
| Crear compromisos (único o recurrente: semanal, quincenal, mensual; fin indefinido, por fecha o por número de pagos) y editar un pago | ✅ |
| Crear, editar y eliminar cuentas, tarjetas (con disponible y estado pendiente al registrarla), préstamos (con cargos mensuales y compromiso mensual) y fuentes de ingreso | ✅ |
| Foto de contacto, plantilla de descuentos de una fuente, editar la regla de una serie, perfil, categorías | ⏳ |

### iOS (Swift)
| Pantalla | Estado |
|---|---|
| Login, registro, Google, olvido/reset de contraseña | ✅ |
| Perfil | Básico |
| Todo lo demás | ⏳ |

### Calidad
- 139 tests automáticos (web y API: auth, perfil, categorías, efectivo, ciclos, compromisos, pago con tarjeta, cuentas, transferencias, tarjetas, hogar, contactos y deudas, préstamos, ingresos, dashboard, permisos, catálogos de tipos)
- Ojo: los tests usan SQLite; PostgreSQL no admite `ORDER BY` en consultas con agregados → las relaciones que se suman (`pagos()`, `cargos()`, `deudas()`) no llevan orden
- Los tests usan SQLite en memoria; `tests/TestCase.php` impide que borren la base de desarrollo

---

## 13. PRÓXIMOS PASOS (en orden)

1. ~~Mover lógica a servicios~~ ✅ (efectivo, compromisos, categorías; validación compartida en `app/Http/Requests`)
2. ~~Rediseño de compromisos~~ ✅ (17/10/2026)
3. ~~Cuentas bancarias~~ ✅ · ~~Tarjetas de crédito~~ ✅
4. ~~Hogar (grupos familiares)~~ ✅ (19/10/2026)
5. ~~Importador de la hoja de cálculo~~ ✅ (19/10/2026) — todo 2026 (efectivo, cuentas, transferencias, 14 tarjetas con estado pendiente y sin facturar, avances, compromisos) a los correos destino; local en `.claude/datos/` (no se sube). Se amplía con cada módulo
6. ~~Deudores~~ ✅ (19/10/2026) → ~~Contactos~~ ✅ (20/10/2026)
7. ~~Préstamos~~ ✅ (19/10/2026) con tabla de pagos y proyecciones
8. ~~Ingresos con desglose~~ ✅ (20/10/2026)
9. ~~Intereses de tarjeta~~ ✅: en cada tarjeta, lo que costaría no pagar completo cada estado de cuenta (pendiente × tasa × 30 / 365 + pago tardío) y cómo cobran los bancos
10. ~~Dashboard~~ ✅ (20/10/2026)
10b. ~~Pagar un compromiso con otra tarjeta~~ ✅ y ~~catálogos de tipos administrables~~ ✅ (20/10/2026)
11. **Móviles al día:** Android ✅ base + inicio, efectivo y compromisos (20/10/2026); ✅ API y pantallas de cuentas, transferencias y tarjetas (21/10/2026). ✅ contactos, préstamos, ingresos, crear compromisos y formularios de alta (21/10/2026). Falta en Android: foto de contacto, plantilla de una fuente, perfil y categorías → luego **iOS**
12. Categorías del sistema con iconos · Wizard (cuentas y tarjetas) · Eventos · Reportes · Notificaciones push
13. **Asesor con IA:** el sistema calcula los escenarios y una IA (proveedor intercambiable) da el consejo; se le manda un resumen sin datos identificables

---

## 14. NOTAS TÉCNICAS Y CONVENCIONES

- **Nomenclatura:** rutas y permisos en kebab-case (`marcas-red.create`), tablas en plural snake_case, modelos en PascalCase singular
- **Transacciones de BD** en toda operación que mueva dinero, con `lockForUpdate` cuando se modifican saldos
- **Imágenes:** `storage/app/public/...` vía `asset('storage/...')`
- **Estilo:** PSR-12 con Laravel Pint
- **Comandos útiles** (desde la raíz del repositorio):
  - Tests: `docker compose exec app php artisan test`
  - Estilo: `docker compose exec app ./vendor/bin/pint`
  - Migraciones: `docker compose exec app php artisan migrate`

### Apps móviles: notas
- `GET /api/catalogos` responde sin la envoltura `data` (el resto de recursos sí la usan).
- Gson omite los nulos: al editar, lo que no se envía no cambia (no sirve para vaciar un campo opcional).

### Frontend: reglas aprendidas
- Los ayudantes de formulario (`validateDateInput`, `syncDateInput`, `applyClientFieldLimits`, formato de dinero) son **globales** en `resources/js/app.js`: no dependen de que la pantalla tenga una tabla de datos.
- Dentro de atributos de un **componente** Blade (`<x-danger-button x-on:click="...">`) no usar `@js(...)` (no se compila): usar `{{ \Illuminate\Support\Js::from(...) }}`.
- Un modal dentro de otro modal se recorta: usar `<template x-teleport="body">` (ej. los recortadores de fotos y logos).
- No mezclar `@php(...)` en línea con un bloque `@php … @endphp` en la misma vista: Blade los confunde y da error de sintaxis.
- Rutas con `->defaults('catalogo', …)`: Laravel pasa los valores por defecto **después** de los parámetros de la URL; el método recibe `($id, $catalogo)` en ese orden.
- Revisar errores de JavaScript en un navegador real (Chrome sin interfaz) además de los tests del servidor.

### Consideraciones pendientes
- Soft deletes en registros importantes
- Auditoría (quién creó/modificó/eliminó)
- Cache para cálculos frecuentes (efectivo actual)
- Colas para tareas pesadas (reportes)

### UX/UI
- Responsive (mobile-first), modo claro/oscuro
- Colores de las marcas de tarjetas, iconos claros por categoría
- Confirmación en acciones destructivas, estados de carga y mensajes de error amigables

---

## 15. GLOSARIO

- **Ciclo / corte:** período financiero del usuario; termina en su día de corte
- **Compromiso:** gasto programado o deuda por pagar
- **Compromiso padre / hijo:** regla recurrente / instancia de cada ciclo
- **Efectivo actual:** dinero en efectivo que el usuario tiene ahora
- **Saldo arrastrado:** efectivo con el que abre un ciclo (informativo, no suma al balance)
- **Liquidez neta:** disponible menos compromisos
- **Deudor:** persona o institución que le debe dinero al usuario
- **Festejado:** persona invitada en un evento; su consumo lo pagan los demás
- **Tasa cero:** compra en cuotas sin intereses

---

**FIN DEL DOCUMENTO**
