# Decisiones técnicas del backend

Decisiones ya cerradas para el equipo (no rediscutir sin razón explícita) y
propuestas técnicas que siguen en borrador. A diferencia de las notas de
trabajo internas de cada desarrollador, este archivo sí viaja en el repo:
solo debe contener lo que ya es acuerdo de equipo o una propuesta explícita
sobre la que se pide validación, nunca discusiones en curso o notas
personales.

## Roles (actualizado el 24 nov 2026 — RN-02)

`spatie/laravel-permission` con el modelo propio `App\Models\Rol` (columnas
`etiqueta`, `descripcion`, `cupo_prestamos`, `es_predeterminado`). Cada
usuario tiene **un solo rol a la vez**:

| Rol | Tipo | Corresponde a |
|---|---|---|
| `admin` | Sistema | Administrador |
| `encargado` | Sistema | Personal de préstamo |
| `estudiante` | Solicitante (predeterminado) | Solicitante; antes se llamaba `usuario` |
| `profesor`, `coordinador`… | Solicitante | Roles que crea el admin en `/api/roles` |

- Los roles de sistema son los únicos que dan permisos (middleware
  `role:admin` y `role:admin|encargado`); por eso no se pueden borrar y su
  nombre técnico no cambia.
- Los de solicitante solo cambian el **cupo** de préstamos vivos (RN-02) y
  los días extra de duración (RN-06).
- El cupo vive en `roles.cupo_prestamos`, no en `configuraciones`: así un rol
  nuevo nace con su cupo y al borrarlo no quedan claves huérfanas.
- La migración `2026_11_24_000008` renombra `usuario` → `estudiante`
  (conserva las asignaciones, que van por id), copia `cupos.roles` a la
  columna y borra esa fila.

Los roles iniciales los crea `database/seeders/RoleSeeder.php` de forma
idempotente (`firstOrCreate`, sin pisar cupos ajustados). Detalle de la API en
[`docs/api/roles.md`](api/roles.md).

## Modelo de datos — alcance funcional (cerrado por el PO, 2026-09-05)

| Tabla / funcionalidad | Decisión | Nota |
|---|---|---|
| `categorias` | Incluir | Agrupa equipos por tipo (portátil, tablet, de mesa...) — HU-03 |
| `unidades_equipo` | No va | Redundante: cada equipo tiene su propio código/serial y se gestiona individual (así quedó implementado en HU-03) |
| `renovaciones` | Incluir | Prorrogar/devolución: editar fecha fin del préstamo |
| `evidencias` | Incluir | Fotos/URLs de condición al entregar y devolver |
| `notificaciones` | No va | Sin uso real; solo alerta de no-devolución en dashboard |
| `mantenimientos` | Diferido | Baja prioridad, último sprint si alcanza |
| `lista_espera` | En espera | Pendiente de consultar con el profesor (cliente) |
| `parametros_sistema` | Opcional | Sin decisión explícita todavía (configuración utilitaria) |
| `historial_estados` | Se mantiene | Trazabilidad/auditoría de cambios de estado — implementada en HU-04 |

Reemplaza la propuesta anterior de 12 tablas: esta es la versión acordada.

## Matriz de permisos (propuesta — pendiente de validación del equipo)

⚠️ Todavía no está validada por el equipo. No tratar como definitiva ni
generar `Permission::create(...)` en un seeder a partir de esto sin
confirmar primero con el equipo.

⚠️ **Desactualizada tras la decisión de modelo de datos de arriba**: los
permisos `unidades.*` referencian `unidades_equipo`, que ya no va a existir
(ver tabla anterior). Se dejan tal cual hasta que el equipo la revise, no se
reescriben unilateralmente.

```
equipos.ver, equipos.crear, equipos.editar, equipos.eliminar
unidades.ver, unidades.crear, unidades.editar
prestamos.crear, prestamos.ver_propios, prestamos.ver_todos
prestamos.aprobar, prestamos.rechazar, prestamos.entregar, prestamos.recibir
mantenimientos.gestionar
reportes.ver
parametros.gestionar
```

- `admin`: todos los permisos
- `encargado`: `unidades.*`, `prestamos.ver_todos/aprobar/rechazar/entregar/recibir`, `mantenimientos.gestionar`, `equipos.ver`
- `usuario`: `equipos.ver`, `prestamos.crear`, `prestamos.ver_propios`

## Catálogo de equipos (HU-03 y HU-04)

`equipos` — cada fila es una unidad física identificada por su propio
`codigo` (único). Campos: `codigo`, `nombre`, `categoria_id` (FK a
`categorias`), `descripcion`, `estado`, `observaciones`.

- **`estado`** tiene 4 valores fijos: `disponible` (inicial, lo asigna el
  sistema al registrar — no es un campo de entrada), `en_prestamo`,
  `mantenimiento`, `dado_de_baja`. Constantes en `App\Models\Equipo::ESTADOS`
  / `ESTADO_INICIAL` / `ESTADO_TERMINAL`.
- **`dado_de_baja` es terminal**: un equipo en ese estado no puede volver a
  cambiar de estado — así nunca puede llegar a `en_prestamo` (HU-04: "un
  equipo dado de baja no debe poder ser prestado").
- **`categorias`** no tiene CRUD propio todavía — se siembra con
  `CategoriaSeeder` (`Portátil`, `Tablet`, `De mesa`) hasta que exista una HU
  para administrarlas.
- **`historial_estados`** (HU-04): cada cambio de `estado` (incluida la
  asignación inicial al registrar) queda registrado ahí — quién lo hizo y
  cuándo. Lo llena `App\Observers\EquipoObserver`, no el controller, para
  que cualquier código futuro que toque `estado` quede cubierto sin tener
  que acordarse de loguearlo a mano.

Ver `docs/api/equipos.md` para el contrato completo de la API.

## Ciclo de préstamos (HU-06, HU-08, HU-09, HU-11)

Tabla `prestamos`: `usuario_id` (FK a `users`), `equipo_id` (FK a `equipos`), `estado`, `motivo`, `fecha_solicitud`, `fecha_inicio`, `fecha_devolucion_estimada`, `fecha_aprobacion`, `fecha_rechazo`, `fecha_entrega_real`, `fecha_devolucion_real`, `condicion_entrega`, `condicion_devolucion`, `gestionado_por` (FK a `users`), `entregado_por` (FK a `users`), `recibido_por` (FK a `users`), `motivo_rechazo`, `observaciones_entrega`, `observaciones_devolucion`, `timestamps`.

Decisiones adoptadas en esta rama:
- **Campo `motivo` (cerrado por el PO, Jira KAN-20 / KAN-93):** Obligatorio, texto de hasta 1000 caracteres. Se almacena recortado (`trim`).
- **Campos diferidos a refinamiento (fuera de alcance en este sprint):** Descripción del préstamo, número de inventario interno, tiempo en horas y consideración por pérdida o falla.
- **Aprobación y rechazo de préstamos (cerrado, HU-08 / KAN-22, KAN-97, KAN-98, KAN-99):** Incorporado al Sprint 1 para completar el ciclo de vida del préstamo antes de la entrega física. Exclusivo para roles administrativos (`admin`, `encargado`).
- **Columnas de gestión y trazabilidad:** Se agregan `gestionado_por` (FK a `users`, nullable, null on delete), `fecha_rechazo` (datetime, nullable) y `motivo_rechazo` (text, nullable). El actor gestor se registra tanto en la aprobación (`fecha_aprobacion` + `gestionado_por`) como en el rechazo (`fecha_rechazo` + `gestionado_por` + `motivo_rechazo`).
- **Motivo de rechazo obligatorio y regla reutilizable `TextoNoVacio`:** El rechazo exige obligatoriamente un motivo de hasta 1000 caracteres no vacío ni compuesto solo por espacios (validado mediante la regla invokable reutilizable `App\Rules\TextoNoVacio`). Tanto en el rechazo como en la solicitud, los motivos se almacenan recortados (`trim`). El motivo de rechazo es visible tanto para el personal administrativo como para el solicitante dueño del préstamo.
- **Liberación de cupo y disponibilidad:** Una solicitud rechazada no modifica el equipo físico (que permanece disponible para otros préstamos o para que el mismo solicitante lo vuelva a pedir), y no computa dentro del cupo de préstamos activos del usuario (`max_activos_por_usuario`).
- **Filtro por estado en listado (`GET /api/prestamos?estado=`):** Se implementa el filtrado exacto por estado respetando la política de visibilidad del usuario (un usuario común filtra sobre sus propios préstamos; el personal administrativo filtra sobre la totalidad). Valores no soportados responden con HTTP 422 estandarizado (`errors.estado`).
- **Actor `recibido_por` (cerrado):** Corresponde al personal administrativo que recibe físicamente la devolución del equipo.
- **Parámetros de negocio en `config/prestamos.php` (cerrado):** 7 días (`duracion_maxima_dias`) y 3 préstamos activos (`max_activos_por_usuario`) quedan como valores por defecto configurables por `.env`. *(Superado: desde la iteración 2 el cupo es por rol — `roles.cupo_prestamos`, ver «Roles» — y la duración por categoría y rol.)*
- **Trazabilidad del préstamo por columnas de fecha y actor:** Sin tabla de historial de préstamos propia. Cada momento queda registrado en sus columnas correspondientes (`fecha_solicitud`, `fecha_aprobacion` + `gestionado_por`, `fecha_rechazo` + `gestionado_por`, `fecha_entrega_real` + `entregado_por`, `fecha_devolucion_real` + `recibido_por`). Los cambios de estado físico del equipo provocados por la entrega y la devolución quedan auditados automáticamente en `historial_estados` vía `EquipoObserver`.
- **Separación de observaciones y restricción de visibilidad (cerrado, HU-09):** Se separan en dos columnas independientes (`observaciones_entrega` y `observaciones_devolucion`, tipo text, null). Se eliminan prefijos y concatenaciones. En `PrestamoResource`, ambas columnas se exponen únicamente si quien consulta es personal administrativo (`admin` o `encargado`), manteniéndose ocultas para el usuario solicitante. El solicitante sigue viendo `condicion_entrega` y `condicion_devolucion` con sus etiquetas. Los endpoints de entrega y devolución siguen recibiendo el campo `observaciones` en el payload de la petición.
- **Manejo de zona horaria y fechas en la API:** La base de datos y la aplicación Laravel se mantienen en UTC (`app.timezone = 'UTC'`). La zona de negocio se configura en `config('prestamos.zona_horaria')` (`America/Bogota`, UTC-5). La clase `App\Support\FechaNegocio` encapsula el parseo estricto (rechaza fechas sin hora, cadenas relativas o con barras) y la normalización a UTC que consumen los Form Requests. En la salida, `PrestamoResource` serializa las siete fechas de préstamo junto con `created_at` y `updated_at` con copia en memoria hacia la zona de negocio (`-05:00`) mediante `FechaNegocio::formatear()` sin mutar el modelo en UTC, mientras que `HistorialEstadoResource` y timestamps estándar de otras entidades continúan en UTC (`Z`). Ambos son instantes ISO 8601 estándar.
- **Privacidad de usuarios en recursos de préstamos:** En `PrestamoResource`, `usuario_gestion`, `usuario_entrega` y `usuario_recepcion` se restringen siempre a la representación mínima `{ id, name }` (igual que en `HistorialEstadoResource`) para no exponer correo ni roles del personal administrativo a usuarios regulares. En `solicitante`, se expone `{ id, name }` a usuarios regulares y se reservan `email`, `is_active` y `roles` únicamente cuando quien consulta es personal administrativo (`esPersonalAdministrativo()`).
- **Orden de bloqueo único ante concurrencia:** Para evitar interbloqueos (*deadlocks*) y carreras en MySQL 8.0, todo el módulo de préstamos sigue un orden estricto y unidireccional de adquisición de bloqueos pesimistas (`lockForUpdate()`): `usuario -> equipo -> préstamo`. En `SolicitudPrestamoService` se bloquea al solicitante y luego al equipo (para evaluar `max_activos_por_usuario` con datos frescos); en `AprobacionPrestamoService`, `EntregaPrestamoService` y `DevolucionPrestamoService` se bloquea el equipo y luego el préstamo en `TransicionPrestamoService`. En `RechazoPrestamoService`, al no interactuar ni modificar el equipo físico, solo se bloquea el préstamo vía `TransicionPrestamoService`.

Ver `docs/api/prestamos.md` para el contrato completo de la API.

## Revisión del equipo en la devolución (HU-12)

Tabla `revisiones_equipo`: `prestamo_id` (FK a `prestamos`, **único**),
`equipo_id` (FK a `equipos`), `condicion` (enum `CondicionEquipo`),
`tiene_danos` (bool), `observaciones` (text, null), `revisado_por` (FK a
`users`, null on delete), `fecha_revision`, `incidencia_id` y `timestamps`.

Decisiones adoptadas en esta rama:

- **Una revisión por préstamo (cerrado, KAN-125):** la revisión de devolución
  es un hecho único del ciclo de vida, no una bitácora. La unicidad la
  garantiza la base de datos con un índice único sobre `prestamo_id`, y
  `RevisionEquipoService` la valida antes de insertar para devolver un 422 en
  español en vez de un error de llave duplicada.
- **`incidencia_id` sin foreign key (cerrado, KAN-125):** la columna queda
  `nullable` e indexada pero **sin** restricción de integridad, porque la tabla
  de incidencias de la HU-31 todavía no existe. Permite vincular la incidencia
  después sin una segunda migración. Cuando exista, añadir la FK.
- **`tiene_danos` derivado pero persistido:** se deriva de la condición
  (`RevisionEquipo::condicionTieneDanos()`, única fuente de verdad), y se
  almacena para poder filtrar y contar revisiones con novedad sin conocer los
  valores del enum. El scope `conDanos()` es el insumo previsto para la HU-31.
- **⚠️ Duplicación deliberada con `prestamos` (KAN-127):** las columnas
  `condicion_devolucion`, `observaciones_devolucion` y `recibido_por` de
  `prestamos` **se mantienen** y se siguen escribiendo. La revisión es la
  **fuente de verdad** de la revisión física; esas columnas quedan como espejo
  de solo lectura, escrito en la misma transacción. Se eligió así para no
  romper el contrato de `PrestamoResource` ni las pruebas existentes. El costo
  es real: son dos lugares que describen el mismo hecho y pueden divergir si
  algún código futuro escribe uno sin el otro. **Cualquier escritura nueva debe
  pasar por `DevolucionPrestamoService`**, nunca actualizar esas columnas
  directamente. Si más adelante se decide una sola fuente, el cambio implica
  reescribir `PrestamoResource`, `DevolucionPrestamoTest` y
  `ContratoPrestamoResourceTest`.
- **Atomicidad (cerrado, KAN-127):** la revisión se crea dentro de la **misma**
  transacción de la devolución, antes de la transición de estado (porque
  `RevisionEquipoService` exige que el préstamo esté todavía en `entregado`).
  Si la revisión falla, la devolución entera se revierte y el equipo no cambia
  de estado.
- **La máquina de estados habla primero:** `DevolucionPrestamoService` valida
  la transición antes de registrar la revisión, para que un préstamo en estado
  incorrecto reciba el error de la transición y no el de la revisión. El texto
  vive en `EstadoPrestamo::mensajeTransicionInvalida()` para tener una sola
  fuente.
- **Visibilidad (cerrado, KAN-127):** en `RevisionEquipoResource` las
  `observaciones` se exponen solo a personal administrativo, mismo criterio que
  `observaciones_entrega` / `observaciones_devolucion`. El solicitante sí ve
  `condicion`, `condicion_etiqueta` y `tiene_danos`. `revisado_por` se expone
  como id y **no** se carga su relación: el nombre ya viaja en
  `usuario_recepcion` del préstamo y evitar la relación mantiene acotado el
  presupuesto de consultas del listado.
- **Endpoint propio para la consulta (cerrado, KAN-130):**
  `GET /api/equipos/{equipo}/revisiones`, exclusivo de `admin` y `encargado`.
  No se sumó a `GET /api/equipos/{equipo}/historial` porque ese endpoint lo
  consulta cualquier autenticado y mezclaría datos de visibilidad distinta en
  una misma lista, además de cambiar un contrato ya documentado.
- **Presupuesto de consultas del listado de préstamos:** `revision` entra en
  `Prestamo::RELACIONES_RECURSO` para que la clave exista en todas las
  respuestas (null mientras no haya revisión). Eso sumó una consulta constante
  y el umbral del test de N+1 subió de 9 a 10. El comentario de ese test
  contaba 8 cuando la línea base real ya era 9 (faltaba la consulta de
  autenticación del token); quedó corregido.

Ver `docs/api/equipos.md` para el contrato del endpoint de revisiones.

## Consulta de solicitudes y préstamos (HU-07 y HU-10)

- **Definición de préstamo "activo" (cerrado en esta rama, KAN-119):**
  `solicitado`, `aprobado` y `entregado`. Son los estados en que el préstamo
  sigue vivo y compromete al solicitante y al equipo. `rechazado`, `cancelado`
  y `devuelto` **nunca** son activos: son finales, liberan cupo y dejan el
  equipo libre.

  La definición vive en **`EstadoPrestamo::activos()`** (con
  `valoresActivos()` y `esActivo()`), y es la **fuente única**: la consumen
  tanto el cupo `max_activos_por_usuario` en `SolicitudPrestamoService` —que
  antes repetía la lista a mano, dos veces— como el filtro `?activos=` del
  listado. Así no pueden divergir.

  Se eligió este conjunto porque es el que ya aplicaba de hecho el cupo por
  usuario desde la HU-06; `docs/decisiones.md` lo mencionaba pero nunca
  enumeraba los estados.

- **⚠️ "Activo" tiene dos alcances distintos en la API, a propósito:**

  | Dónde | Qué incluye |
  |---|---|
  | `?activos=1` en `GET /api/prestamos` | `solicitado` + `aprobado` + `entregado` |
  | `GET /api/prestamos/activos` (HU-11) | solo `entregado` |

  El endpoint de la HU-11 responde a "qué equipos están ahora mismo fuera, en
  manos de alguien", mientras que el filtro de la HU-10 responde a "qué
  préstamos míos siguen vigentes", que para el solicitante incluye los que
  todavía espera que le aprueben o le entreguen. Se mantienen separados
  porque responden preguntas distintas, pero **queda documentado en
  `docs/api/prestamos.md`** para que nadie asuma que son equivalentes. Si en
  el futuro molesta, lo que habría que cambiar es el nombre del endpoint de la
  HU-11, no la definición del enum.

- **Combinación de filtros (cerrado, KAN-119):** `?estado=`, `?activos=` y el
  filtro automático por usuario se acumulan como **AND**, igual que los
  filtros de `/api/equipos`. Una combinación contradictoria
  (`?estado=devuelto&activos=1`) devuelve **lista vacía**, no un error: es
  predecible y no añade casos especiales. Un `?estado=` inválido sigue dando
  422 aunque venga acompañado de `?activos=`. Se descartó dar prioridad a uno
  de los dos, porque ignorar en silencio un parámetro que el cliente envió
  esconde errores.

- **HU-10 no necesitó endpoint nuevo (KAN-118):** `GET /api/prestamos` ya
  filtraba por `usuario_id` cuando quien consulta no es personal, y
  `PrestamoResource` ya exponía equipo, `fecha_entrega_real`,
  `fecha_devolucion_estimada` y estado. La tarea se cubrió solo con pruebas.

- **Orden de las solicitudes pendientes (cerrado, KAN-104):**
  `GET /api/prestamos/pendientes` ordena por `fecha_solicitud` **ascendente**
  (con desempate por `id`), al revés que `GET /api/prestamos`. El criterio es
  el orden de atención: primero la solicitud que lleva más tiempo esperando.

- **Respuesta paginada unificada (ampliado en review):** el bloque que arma
  `data` + `meta` estaba repetido en `index()` y `activos()`, y también en
  `EquipoController` (catálogo, historial y revisiones) y en `UserController`.
  Al revisar la HU-12 se extrajo al trait
  `App\Http\Controllers\Concerns\RespuestaPaginada`, que usan los siete listados
  paginados: `per_page` se acota igual en todos y el bloque `meta` ya no se
  puede escribir dos veces distinto.

- **`?activos=` valida su valor (corregido en el review de la HU-10):** antes
  cualquier texto distinto de `1/0/true/false` caía en `false` y devolvía los
  préstamos **cerrados** sin avisar, mientras un `?estado=` inválido sí
  respondía 422. Ahora un valor no booleano responde **422
  (`errors.activos`)**: adivinar la intención esconde errores del cliente. Se
  conservan las formas booleanas habituales (`1`, `0`, `true`, `false`, `on`,
  `off`, `yes`, `no`) y `?activos=` vacío sigue sin filtrar.

- **Un solo bloqueo del préstamo en la devolución (ajustado en review):** la
  devolución bloqueaba la fila de `prestamos` tres veces en la misma
  transacción (orquestador, revisión y transición). `RevisionEquipoService::registrar()`
  acepta ahora la fila ya bloqueada por el llamador, así que se ahorra un
  `SELECT ... FOR UPDATE` sin cambiar el orden de bloqueo ni la semántica.

- **N+1 corregido en `PrestamoResource` (hallado al implementar KAN-105):**
  el recurso expone los roles del solicitante con `getRoleNames()`, que
  disparaba **una consulta por fila**. No se notaba porque las pruebas
  existentes usaban un único solicitante para todas las filas, y el eager
  loading comparte la instancia; con un solicitante distinto por fila —el caso
  normal en el listado de solicitudes pendientes— eran N consultas extra. La
  corrección es cargar `solicitante.roles` en
  `Prestamo::RELACIONES_RECURSO`, lo que sustituye N consultas por una
  constante. **Al añadir campos de relación a `PrestamoResource`, revisar
  siempre que la relación esté en `RELACIONES_RECURSO`.**

Ver `docs/api/prestamos.md` secciones 3 y 7b para el contrato completo.

## Estrategia de pruebas y doble motor: SQLite y MySQL 8.0

- **Ejecución por defecto en SQLite (`composer test`):** Los tests corren por defecto en SQLite en memoria (`:memory:`) mediante `phpunit.xml`. Su objetivo es brindar ciclos de retroalimentación rápidos durante el desarrollo local e iterativo.
- **Validación en MySQL 8.0 (`composer test:mysql`):** Antes de abrir o actualizar cualquier Pull Request, la suite completa debe ejecutarse contra MySQL 8.0 sobre la base aislada `prestamos_test` mediante `phpunit.mysql.xml`.
- **Motivación técnica:** SQLite no replica fielmente el comportamiento de MySQL 8.0: no valida bloqueos pesimistas (`lockForUpdate()`), índices, restricciones de dialecto SQL ni el manejo estricto de tipos y claves foráneas del motor de producción.
- **Aislamiento y salvaguarda (`GuardiaBaseDatos`):** La suite cuenta con una guardia estricta en el ciclo de vida de `TestCase` y en `EntornoDePruebasTest` que aborta si la conexión es MySQL y la base de datos no termina en `_test`, o si es SQLite y no está en memoria. Esto garantiza que la base de desarrollo local (`prestamos_equipos`) nunca sea modificada ni limpiada por `RefreshDatabase`.
- **Limitación conocida:** Las dos suites se ejecutan de manera secuencial dentro del proceso de pruebas. La concurrencia real entre peticiones HTTP simultáneas (dos peticiones concurrentes disputando el mismo equipo o el cupo de préstamos) no la cubre ninguna de las dos suites automáticas, por lo que la protección recae en el diseño estricto de transacciones atómicas con bloqueos pesimistas unidireccionales (`lockForUpdate()`).
- **No ejecución simultánea de `test:mysql`:** La suite contra MySQL utiliza una única base compartida (`prestamos_test`) y la recrea al iniciar (`migrate:fresh`). Por esta razón, no se pueden ejecutar dos corridas de `test:mysql` al mismo tiempo (ej. en dos terminales en paralelo o mientras un agente de IA la ejecuta). Si se ejecutan en simultáneo, colisionarán en la creación/migración de tablas produciendo errores de *"Table doesn't exist"*, *"Table definition has changed"* o *"Deadlock"* en pruebas que normalmente pasan limpias.

