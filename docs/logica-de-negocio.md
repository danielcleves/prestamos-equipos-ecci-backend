# Lógica de negocio — Préstamos de Equipos ECCI (backend)

Traza completa del dominio tal como está implementado en la rama `develop`
(escaneo del código, no de la documentación de intenciones). Sirve como punto
de partida para el trabajo nuevo: lo que ya existe, dónde vive cada regla, y
qué está declarado pero sin implementar.

- Stack: Laravel (API pura, sin vistas), Sanctum para tokens,
  `spatie/laravel-permission` para roles, MySQL 8.0 en producción.
- Idioma del dominio: todo el modelo, los estados y los mensajes están en
  español; el inglés queda solo en el andamiaje de Laravel.
- Fuente de verdad de las decisiones de equipo: [docs/decisiones.md](decisiones.md).
  Contratos de API por módulo: [docs/api/](api/).

---

## 1. Actores y roles

Tres roles fijos, **uno por usuario**, creados idempotentemente por
[RoleSeeder.php](../database/seeders/RoleSeeder.php):

| Rol | Actor del negocio | Qué puede hacer |
|---|---|---|
| `admin` | Administrador | Todo: usuarios, catálogo de equipos, ciclo de préstamos |
| `encargado` | Personal de préstamo | Ciclo de préstamos (aprobar, rechazar, entregar, recibir) + ver catálogo |
| `usuario` | Solicitante | Ver catálogo, solicitar préstamos, ver los propios |

El agrupador que usa el código no es el rol individual sino el concepto
**personal administrativo** = `admin` o `encargado`, en
[User::esPersonalAdministrativo()](../app/Models/User.php#L38). Es el pivote
de casi toda la autorización de lectura y de la visibilidad de campos
sensibles.

Hay además un eje de acceso independiente del rol: `users.is_active`. Un
usuario inactivo no puede autenticarse aunque sus credenciales sean correctas
([AuthController::login](../app/Http/Controllers/Api/AuthController.php#L33)).

> **Nota:** la matriz de permisos granulares (`prestamos.aprobar`, etc.) está
> *propuesta* en `docs/decisiones.md` pero **no implementada**. Hoy la
> autorización es exclusivamente por rol vía middleware `role:`.

---

## 2. Entidades del dominio

```
Categoria 1---N Equipo 1---N Prestamo N---1 User (solicitante)
                   |            |     \
                   |            |      +-- User (gestionado_por / entregado_por / recibido_por)
                   |            |
                   |            +---1 RevisionEquipo ---1 User (revisado_por)
                   |                      (una por préstamo)
                   +---N HistorialEstado ---1 User (quién cambió el estado)
                   |
                   +---N RevisionEquipo (todas las revisiones del equipo)
```

| Entidad | Tabla | Papel en el negocio |
|---|---|---|
| `Categoria` | `categorias` | Agrupa equipos por tipo. **Sin CRUD**: se siembra con `Portátil`, `Tablet`, `De mesa` |
| `Equipo` | `equipos` | Unidad física individual, identificada por `codigo` único |
| `Prestamo` | `prestamos` | El ciclo de vida completo de un préstamo, con fechas y actores por hito |
| `RevisionEquipo` | `revisiones_equipo` | Revisión del estado del equipo en la devolución (HU-12). Una por préstamo |
| `HistorialEstado` | `historial_estados` | Auditoría de cambios de estado **del equipo** (no del préstamo) |
| `User` | `users` | Solicitante y/o personal, con rol Spatie e `is_active` |

Las FK a `users` y `equipos` desde `prestamos` son `restrictOnDelete` (no se
borra un usuario o equipo con préstamos); las de actores de gestión son
`nullOnDelete`.

---

## 3. Máquina de estados del equipo

Cuatro estados fijos en [Equipo::ESTADOS](../app/Models/Equipo.php#L36):

| Estado | Significado |
|---|---|
| `disponible` | Estado **inicial**, asignado por el sistema al registrar. Único estado prestable |
| `en_prestamo` | Entregado físicamente a un solicitante |
| `mantenimiento` | Fuera de servicio temporalmente |
| `dado_de_baja` | **Terminal**: no puede volver a cambiar de estado nunca |

Reglas clave:

- **`disponible` es la única fuente de verdad de disponibilidad.** Tanto
  `Equipo::isDisponible()` como `scopeDisponible()` consultan ese valor y son
  los únicos puntos que el resto del sistema debe consumir.
- **`dado_de_baja` es irreversible**, garantizado por la regla
  [EquipoNoDadoDeBaja](../app/Rules/EquipoNoDadoDeBaja.php) sobre el endpoint
  de cambio de estado. Esto es lo que asegura que un equipo de baja nunca
  pueda llegar a `en_prestamo`.
- **Visibilidad:** los equipos `dado_de_baja` solo son visibles para personal
  administrativo (`scopeVisiblesEnCatalogo` + `puedeVerDadosDeBaja`). A un
  usuario regular que consulte uno se le responde **404**, no 403 — para no
  revelar que el registro existe.
- **Toda transición queda auditada automáticamente** en `historial_estados`
  por [EquipoObserver](../app/Observers/EquipoObserver.php), incluida la
  asignación inicial al crear (`estado_anterior = null`). Está en un observer
  deliberadamente: cualquier código futuro que toque `estado` queda cubierto
  sin acordarse de loguear a mano.

Quién mueve el estado del equipo:

| Transición | Disparador |
|---|---|
| `null → disponible` | Registro del equipo (`POST /api/equipos`) |
| cualquiera → cualquiera | `PATCH /api/equipos/{id}/estado` (solo `admin`) |
| `disponible → en_prestamo` | Registro de **entrega** del préstamo |
| `en_prestamo → disponible` | **Devolución** en condición `bueno` |
| `en_prestamo → mantenimiento` | **Devolución** con daños o requiere mantenimiento |

---

## 4. Máquina de estados del préstamo

El corazón del dominio, en [EstadoPrestamo](../app/Enums/EstadoPrestamo.php).
El mapa de transiciones es **explícito y centralizado** en un solo `match`,
para poder añadir estados futuros (p. ej. "con retraso") sin reescribir la
lógica.

```
                    ┌─→ rechazado (final)
                    │
  solicitado ───────┼─→ cancelado (final)
                    │
                    └─→ aprobado ──┬─→ cancelado (final)
                                   │
                                   └─→ entregado ──→ devuelto (final)
```

| Estado | Destinos válidos |
|---|---|
| `solicitado` | `aprobado`, `rechazado`, `cancelado` |
| `aprobado` | `entregado`, `cancelado` |
| `entregado` | `devuelto` |
| `rechazado`, `cancelado`, `devuelto` | — (finales, `isFinal() === true`) |

Un estado final no tiene salida: eso convierte `isFinal()` en simplemente
"no tengo transiciones válidas".

### Columnas por hito (trazabilidad sin tabla de historial)

Decisión de diseño: **el préstamo no tiene tabla de historial propia**. Cada
hito se registra en sus propias columnas de fecha + actor.

| Hito | Fecha | Actor | Datos extra |
|---|---|---|---|
| Solicitud | `fecha_solicitud` | `usuario_id` (solicitante) | `motivo`, `fecha_inicio`, `fecha_devolucion_estimada` |
| Aprobación | `fecha_aprobacion` | `gestionado_por` | — |
| Rechazo | `fecha_rechazo` | `gestionado_por` | `motivo_rechazo` (obligatorio) |
| Entrega | `fecha_entrega_real` | `entregado_por` | `condicion_entrega`, `observaciones_entrega` |
| Devolución | `fecha_devolucion_real` | `recibido_por` | `condicion_devolucion`, `observaciones_devolucion` |

`cancelado` tiene su rama de transición abierta pero sin columnas ni endpoint:
el hueco está marcado en `TransicionPrestamoService::aplicarCancelacion()`.

### Condición del equipo

[CondicionEquipo](../app/Enums/CondicionEquipo.php): `bueno`, `con_danos`,
`requiere_mantenimiento`. Se reporta en entrega (por defecto `bueno`) y en
devolución (obligatoria), y es lo que decide a qué estado vuelve el equipo
tras la devolución.

---

## 5. Reglas de negocio por caso de uso

### 5.1 Solicitar un préstamo — [SolicitudPrestamoService](../app/Services/SolicitudPrestamoService.php)

`POST /api/prestamos` · cualquier autenticado · `throttle:10,1`

Validación de entrada ([StorePrestamoRequest](../app/Http/Requests/Api/StorePrestamoRequest.php)):

1. `equipo_id` debe existir.
2. `motivo` obligatorio, máx. 1000 caracteres, no puede ser solo espacios
   (regla reutilizable [TextoNoVacio](../app/Rules/TextoNoVacio.php)); se
   guarda con `trim`.
3. `fecha_inicio` ≥ ahora, con **tolerancia de 60 segundos** para no castigar
   la latencia de red.
4. `fecha_devolucion_estimada` > `fecha_inicio`, y no más de
   `config('prestamos.duracion_maxima_dias')` (**7** por defecto) después del
   inicio, medido hasta el `endOfDay()` del último día permitido.

Reglas de negocio dentro de la transacción, en este orden:

5. **Cupo:** el usuario no puede tener más de
   `config('prestamos.max_activos_por_usuario')` (**3** por defecto) préstamos
   en estado `solicitado`, `aprobado` o `entregado`. Rechazados, cancelados y
   devueltos **no cuentan** — liberan cupo.
6. **Disponibilidad:** el equipo debe estar `disponible`, con mensajes de error
   diferenciados para `dado_de_baja`, `mantenimiento` y el resto.
7. **No solapamiento:** no puede existir otro préstamo `solicitado`/`aprobado`/
   `entregado` del mismo equipo cuyo rango `[fecha_inicio, fecha_devolucion_estimada)`
   se cruce con el solicitado (comparación de intervalos semiabiertos:
   `inicio_existente < fin_nuevo AND fin_existente > inicio_nuevo`).

Efecto: se crea el préstamo en `solicitado` con `fecha_solicitud = now()`.
**La solicitud no cambia el estado del equipo** — reservar no es ocupar.

### 5.2 Aprobar — [AprobacionPrestamoService](../app/Services/AprobacionPrestamoService.php)

`POST /api/prestamos/{id}/aprobacion` · `admin|encargado`

- Se revalida la disponibilidad física del equipo **con datos frescos**
  (bloqueo pesimista), porque entre la solicitud y la aprobación el equipo
  pudo haberse dado de baja, entrado a mantenimiento o salido en préstamo.
- El préstamo debe estar en `solicitado` (lo valida la máquina de estados).
- **La aprobación no toca el estado del equipo**: eso solo ocurre en la
  entrega física.

### 5.3 Rechazar — [RechazoPrestamoService](../app/Services/RechazoPrestamoService.php)

`POST /api/prestamos/{id}/rechazo` · `admin|encargado`

- `motivo` **obligatorio**, no vacío, máx. 1000 caracteres, guardado con `trim`.
- No consulta ni modifica el equipo, así que es el único servicio del módulo
  que **no** bloquea la fila de `equipos`.
- El `motivo_rechazo` es visible para el personal administrativo **y para el
  solicitante dueño** del préstamo.
- Un rechazo libera cupo y deja el equipo disponible para que otro —o el
  mismo solicitante— lo vuelva a pedir.

### 5.4 Registrar entrega — [EntregaPrestamoService](../app/Services/EntregaPrestamoService.php)

`POST /api/prestamos/{id}/entrega` · `admin|encargado`

- El préstamo debe estar en `aprobado`; el equipo debe estar `disponible`.
- `fecha_entrega_real` es opcional (por defecto `now()`) y se valida contra
  [Prestamo::errorFechaEntregaReal()](../app/Models/Prestamo.php#L113):
  no puede ser futura (con 1 minuto de tolerancia) ni anterior a
  `fecha_aprobacion`.
- `condicion_entrega` por defecto `bueno`.
- Efecto colateral: **el equipo pasa a `en_prestamo`**, y el observer registra
  la fila en `historial_estados`.

### 5.5 Registrar devolución — [DevolucionPrestamoService](../app/Services/DevolucionPrestamoService.php)

`POST /api/prestamos/{id}/devolucion` · `admin|encargado`

- El préstamo debe estar en `entregado`; el equipo debe estar **realmente**
  en `en_prestamo` (se verifica contra la tabla, con mensaje que nombra el
  estado real encontrado).
- `condicion_devolucion` **obligatoria**.
- `observaciones` **obligatorias y no vacías** si la condición no es `bueno`:
  si algo volvió mal, hay que decir qué.
- `fecha_devolucion_real` opcional (por defecto `now()`), validada contra
  [errorFechaDevolucionReal()](../app/Models/Prestamo.php#L129): no futura, no
  anterior a `fecha_entrega_real`.
- **Destino del equipo según la condición:**
  `bueno → disponible`; cualquier otra → `mantenimiento`.
- **Se crea la revisión del equipo (HU-12)** en la misma transacción, antes de
  la transición de estado. Ver 5.10.

### 5.9bis Revisión del equipo en la devolución (HU-12)

La revisión es una entidad propia, `revisiones_equipo`, y la **fuente de verdad
de la revisión física** del equipo al devolverlo. La crea
[RevisionEquipoService](../app/Services/RevisionEquipoService.php), invocado por
`DevolucionPrestamoService` dentro de su misma transacción.

Reglas, en el orden en que se evalúan:

1. El actor debe ser **personal administrativo**. El servicio no asume que
   siempre se le llame desde HTTP, así que no delega esto al middleware.
2. El préstamo debe existir (la instancia recibida puede ya no tener fila).
3. Debe estar en **`entregado`**: es decir, en proceso de devolución. Por eso la
   revisión se registra *antes* de la transición a `devuelto`.
4. No puede tener ya una revisión (**una por préstamo**, con índice único en la
   base de datos y validación previa para responder 422 en español).
5. La condición debe ser un valor válido de `CondicionEquipo`.
6. Si la condición no es `bueno`, las **observaciones son obligatorias**.

`tiene_danos` se deriva de la condición vía
`RevisionEquipo::condicionTieneDanos()` —única fuente de verdad— y se persiste
para poder filtrar y contar novedades sin conocer los valores del enum. El scope
`conDanos()` es el insumo previsto para la HU-31.

`incidencia_id` está `nullable` e indexado **sin foreign key**, a propósito: la
tabla de incidencias de la HU-31 no existe todavía, y así se podrá vincular sin
otra migración.

> **⚠️ Duplicación deliberada.** Las columnas `condicion_devolucion`,
> `observaciones_devolucion` y `recibido_por` de `prestamos` se mantienen y se
> siguen escribiendo, como espejo de solo lectura de la revisión. Son dos
> lugares que describen el mismo hecho y **pueden divergir**: toda escritura
> debe pasar por `DevolucionPrestamoService`, nunca actualizar esas columnas a
> mano. Ver `docs/decisiones.md`.

### 5.10 Consulta de revisiones (HU-12 / KAN-130)

| Endpoint | Rol | Notas |
|---|---|---|
| `GET /api/equipos/{equipo}/revisiones` | `admin\|encargado` | Más recientes primero; filtro `?con_danos=1\|0` |
| `GET /api/prestamos/{id}` | según visibilidad del préstamo | Trae la revisión en la clave `revision` |

Endpoint propio y no parte de `/historial`: ese lo consulta cualquier
autenticado, y las observaciones de la revisión son reservadas al personal.

Las revisiones **se conservan después de cerrado el préstamo** y a lo largo de
varios ciclos de préstamo del mismo equipo: son la trazabilidad que alimenta la
gestión de incidencias.

### 5.6 Consulta de préstamos

| Endpoint | Rol | Reglas |
|---|---|---|
| `GET /api/prestamos` | cualquiera | El `usuario` común ve **solo los suyos** (filtro forzado por `usuario_id`); el personal ve todos. Filtro opcional `?estado=`, valor inválido → 422 |
| `GET /api/prestamos/{id}` | cualquiera | El solicitante solo ve el propio; un préstamo ajeno responde **404** (no 403), para no filtrar su existencia |
| `GET /api/prestamos/activos` | `admin\|encargado` | Préstamos en `entregado`, con búsqueda `?buscar=` por código/nombre de equipo o nombre/email del solicitante |

Paginación uniforme en todo el proyecto: `per_page` acotado a `[1, 100]`,
default 15, y un bloque `meta` con `current_page / last_page / per_page / total`.
El `max(1, ...)` es deliberado: un `per_page` de 0 o negativo rompía MySQL con
un 500.

### 5.7 Catálogo de equipos

| Endpoint | Rol | Notas |
|---|---|---|
| `GET /api/equipos` | cualquiera | Filtros `categoria_id`, `disponible` (bool), `buscar`; orden `nombre` (default), `-nombre`, `disponibles_primero`, `recientes` |
| `GET /api/equipos/{id}` | cualquiera | 404 si es de baja y quien consulta no es personal |
| `GET /api/equipos/{id}/historial` | cualquiera | Historial de estados, más reciente primero |
| `POST /api/equipos` | `admin` | `codigo` único; el `estado` **no es campo de entrada** |
| `PATCH /api/equipos/{id}/estado` | `admin` | Bloqueado si el equipo ya está de baja |

La búsqueda (`scopeBuscar`) hace `LIKE` sobre nombre, código y descripción
escapando `!`, `%` y `_` con `!` como carácter de escape — compatible con
SQLite y MySQL a la vez. El mismo escapado se repite en
`PrestamoController::activos`.

El historial se ordena por `latest('id')`, no por `created_at`: varios cambios
de estado pueden caer en el mismo segundo y el timestamp no los distingue.

### 5.8 Gestión de usuarios

Todo bajo `admin`. `GET/POST/PUT /api/usuarios`, `GET /api/usuarios/{id}`,
`PATCH /api/usuarios/{id}/activar` y `/desactivar`. **Sin `destroy`**: los
usuarios no se borran, se desactivan.

- Al crear, `is_active = true` explícito (el default de columna de MySQL no se
  refleja en el modelo recién creado).
- El rol se asigna con `syncRoles([...])` en el update: siempre exactamente uno.
- **Un admin no puede desactivarse a sí mismo** (422). Sin ese freno quedaría
  sin forma de revertirlo, porque `is_active` solo se cambia desde un endpoint
  que exige ser admin activo.

### 5.9 Autenticación

`POST /api/login` (`throttle:6,1` por IP), `POST /api/logout`, `GET /api/me`.
Tokens personales de Sanctum.

- Credenciales incorrectas y usuario inexistente devuelven **el mismo
  mensaje**: distinguirlos permitiría enumerar qué correos están registrados.
- Cuenta desactivada **sí** tiene mensaje propio: a esa altura el cliente ya
  demostró conocer la contraseña, así que no hay nada nuevo que filtrar.
- `logout` revoca **solo el token de la petición actual**: cerrar sesión en un
  dispositivo no afecta a los demás.

---

## 6. Reglas transversales

### Concurrencia: orden de bloqueo único

Para evitar interbloqueos en MySQL 8.0, **todo el módulo adquiere bloqueos
pesimistas (`lockForUpdate()`) en un único orden unidireccional:**

```
usuario → equipo → préstamo
```

| Servicio | Bloquea |
|---|---|
| `SolicitudPrestamoService` | usuario, luego equipo |
| `AprobacionPrestamoService` | equipo, luego préstamo (vía `TransicionPrestamoService`) |
| `EntregaPrestamoService` | equipo, luego préstamo |
| `DevolucionPrestamoService` | equipo, luego préstamo |
| `RevisionEquipoService` | solo préstamo (el equipo ya viene bloqueado por la devolución) |
| `RechazoPrestamoService` | solo préstamo (no toca el equipo) |

Esto es lo que hace que el cupo por usuario y el no-solapamiento de equipo
sean seguros ante peticiones simultáneas. **Advertencia para quien toque
esto:** `lockForUpdate()` no bloquea en SQLite, así que las carreras solo se
reproducen contra MySQL, y ninguna de las dos suites automáticas cubre
concurrencia HTTP real — la protección vive en el diseño, no en los tests.

### Patrón arquitectónico

```
Route (middleware role:) → FormRequest (validación + authorize)
   → Controller (orquestación mínima, carga de relaciones)
      → Service (DB::transaction + bloqueos + reglas de negocio)
         → TransicionPrestamoService (valida la máquina de estados)
            → Model / Observer (persistencia + auditoría)
      → Resource (serialización + visibilidad por rol)
```

Los controladores no contienen reglas de negocio. `TransicionPrestamoService`
es el **único** punto que cambia `prestamos.estado`, y los cuatro servicios de
caso de uso lo orquestan añadiendo su validación de equipo y sus columnas.

### Fechas y zona horaria — [FechaNegocio](../app/Support/FechaNegocio.php)

- La app y la base viven en **UTC**; la zona de negocio es
  `config('prestamos.zona_horaria')` = `America/Bogota` (UTC-5).
- El parseo es **estricto**: solo `Y-m-d H:i`, `Y-m-d H:i:s`, las variantes con
  `T`, e ISO 8601 con desplazamiento o `Z`. Se rechazan fechas sin hora,
  cadenas relativas ("tomorrow") y fechas con barras.
- Entrada sin desplazamiento → se interpreta en zona de negocio y se normaliza
  a UTC.
- Salida: `PrestamoResource` serializa las siete fechas del préstamo más
  `created_at`/`updated_at` en zona de negocio (`-05:00`), sobre una **copia**
  para no mutar el modelo en memoria. `HistorialEstadoResource` y los
  timestamps de otras entidades siguen en UTC (`Z`). Ambos son ISO 8601
  válidos.

### Visibilidad de campos sensibles ([PrestamoResource](../app/Http/Resources/PrestamoResource.php))

| Campo | Quién lo ve |
|---|---|
| `observaciones_entrega`, `observaciones_devolucion` | Solo personal administrativo |
| `motivo_rechazo` | Personal administrativo **y** el solicitante dueño |
| `solicitante.email / is_active / roles` | Solo personal administrativo |
| `usuario_gestion / usuario_entrega / usuario_recepcion` | Siempre reducidos a `{id, name}`, para nadie exponer correo ni roles del personal |
| `condicion_*` + etiquetas | Todos |

Cada estado y condición se expone por duplicado: el valor crudo
(`estado`) y su etiqueta legible (`estado_etiqueta`), para que el frontend no
tenga que traducir.

### Errores de API ([bootstrap/app.php](../bootstrap/app.php))

Todas las respuestas de `api/*` son JSON con `message` traducido desde
`lang/es/errores.php`, mapeadas de forma centralizada:

| Código | Causa |
|---|---|
| 401 | Sin token, token inválido o revocado |
| 403 | Falta de rol (incluye `UnauthorizedException` de Spatie) |
| 404 | Modelo no encontrado, **o recurso deliberadamente oculto** |
| 405 | Método no permitido |
| 422 | Validación (`errors` por campo) y reglas de negocio |
| 429 | Rate limit, conservando los headers de reintento |
| 500 | Solo con `app.debug = false`, con mensaje genérico |

El guest redirect está anulado (`redirectGuestsTo(fn () => null)`): sin eso,
el middleware `Authenticate` buscaba una ruta `login` inexistente y devolvía
500 en lugar de 401.

Las violaciones de regla de negocio se expresan como `ValidationException`
(422) con la clave del campo responsable (`equipo_id`, `usuario_id`, `estado`,
`equipo`, `fecha_entrega_real`...), no como excepciones propias de dominio.

### Parámetros configurables — [config/prestamos.php](../config/prestamos.php)

| Parámetro | Default | Variable de entorno |
|---|---|---|
| `duracion_maxima_dias` | 7 | `PRESTAMO_DURACION_MAXIMA_DIAS` |
| `max_activos_por_usuario` | 3 | `PRESTAMO_MAX_ACTIVOS_POR_USUARIO` |
| `zona_horaria` | `America/Bogota` | `PRESTAMO_ZONA_HORARIA` |

---

## 7. Mapa de archivos

| Responsabilidad | Archivos |
|---|---|
| Máquinas de estado | [EstadoPrestamo.php](../app/Enums/EstadoPrestamo.php), [CondicionEquipo.php](../app/Enums/CondicionEquipo.php), [Equipo.php](../app/Models/Equipo.php) |
| Reglas de negocio | [app/Services/](../app/Services/) (7 servicios) |
| Validación de entrada | [app/Http/Requests/Api/](../app/Http/Requests/Api/) (10 requests) |
| Reglas reutilizables | [TextoNoVacio.php](../app/Rules/TextoNoVacio.php), [EquipoNoDadoDeBaja.php](../app/Rules/EquipoNoDadoDeBaja.php) |
| Auditoría | [EquipoObserver.php](../app/Observers/EquipoObserver.php) |
| Fechas | [FechaNegocio.php](../app/Support/FechaNegocio.php) |
| Serialización + visibilidad | [app/Http/Resources/](../app/Http/Resources/) |
| Errores centralizados | [bootstrap/app.php](../bootstrap/app.php), [lang/es/errores.php](../lang/es/errores.php) |
| Rutas y autorización | [routes/api.php](../routes/api.php) |

Un detalle de rutas que importa al añadir endpoints: `/prestamos/activos` está
registrada **antes** de `/prestamos/{prestamo}` a propósito. Invertirlo haría
que `activos` se interpretara como un id.

---

## 8. Historias de usuario: qué está hecho

| HU | Tema | Estado |
|---|---|---|
| HU-01 | Autenticación (login/logout/me) | Implementada |
| HU-02 | Gestión de usuarios y roles | Implementada |
| HU-03 | Registro de equipos | Implementada |
| HU-04 | Cambio de estado + historial | Implementada |
| HU-05 | Catálogo con filtros y búsqueda | Implementada |
| HU-06 | Solicitud y consulta de préstamos | Implementada |
| HU-07 | — | No referenciada en el código |
| HU-08 | Aprobación y rechazo | Implementada |
| HU-09 | Registro de entrega | Implementada |
| HU-10 | — | No referenciada en el código |
| HU-11 | Préstamos activos + devolución | Implementada |
| HU-12 | Revisión del equipo en la devolución | Implementada |
| HU-31 | Incidencias | No implementada — `revisiones_equipo.incidencia_id` queda reservado |

## 9. Huecos conocidos (puntos de partida para el trabajo nuevo)

Esto es lo que el escaneo muestra declarado-pero-no-implementado o
explícitamente diferido:

1. **Cancelación de préstamos.** El estado `cancelado` existe en la máquina de
   estados y es alcanzable desde `solicitado` y `aprobado`, pero **no hay
   endpoint, ni columnas, ni actor**. `aplicarCancelacion()` está vacío con un
   comentario de marcador. Es el hueco más visible del dominio.
2. **Detección de retrasos.** No hay nada que compare `fecha_devolucion_estimada`
   contra `now()`. `docs/decisiones.md` menciona "alerta de no-devolución en
   dashboard" y el enum anticipa un futuro estado "con retraso", pero no existe
   código. Los índices de `prestamos` ya se crearon pensando en esto.
3. **Renovaciones / prórrogas.** Decididas como "incluir" por el PO, sin tabla
   ni código.
4. **Evidencias** (fotos/URLs de condición en entrega y devolución). Decididas
   como "incluir", sin implementar. Hoy solo hay `observaciones` en texto —
   `revisiones_equipo` sería el lugar natural donde colgarlas.
4b. **Incidencias (HU-31).** `revisiones_equipo.incidencia_id` ya existe,
   indexado y sin FK, esperando la tabla. Al crearla, añadir la foreign key.
5. **Permisos granulares.** La matriz `equipos.*` / `prestamos.*` sigue siendo
   propuesta sin validar, y está desactualizada (referencia `unidades_equipo`,
   que ya se descartó). Hoy todo es autorización por rol.
6. **CRUD de categorías.** No existe; se siembran tres fijas.
7. **Mantenimientos** (tabla propia) — diferido por el PO a baja prioridad.
   Hoy `mantenimiento` es solo un estado del equipo.
8. **Lista de espera** — pendiente de consultar con el cliente.
9. **Campos de préstamo diferidos:** descripción del préstamo, número de
   inventario interno, duración en horas (hoy solo días), y tratamiento de
   pérdida o falla.

## 10. Cómo validar cambios

```sh
composer test          # SQLite en memoria — ciclo rápido de desarrollo
composer test:mysql    # MySQL 8.0 sobre prestamos_test — OBLIGATORIO antes de un PR
```

SQLite no replica bloqueos pesimistas, índices, dialecto SQL ni el manejo
estricto de tipos y FK de MySQL, así que pasar en SQLite no es suficiente.

[GuardiaBaseDatos](../tests/Support/GuardiaBaseDatos.php) aborta la suite si
la conexión es MySQL y la base no termina en `_test`, o si es SQLite y no está
en memoria: la base de desarrollo local nunca puede ser limpiada por
`RefreshDatabase`.

**No ejecutar dos corridas de `test:mysql` en paralelo** (dos terminales, o
una terminal y un agente): comparten `prestamos_test` y la recrean al iniciar,
y colisionan con errores de tabla inexistente o deadlocks en pruebas que
normalmente pasan limpias.
