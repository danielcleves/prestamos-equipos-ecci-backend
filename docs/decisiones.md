# Decisiones técnicas del backend

Decisiones ya cerradas para el equipo (no rediscutir sin razón explícita) y
propuestas técnicas que siguen en borrador. A diferencia de las notas de
trabajo internas de cada desarrollador, este archivo sí viaja en el repo:
solo debe contener lo que ya es acuerdo de equipo o una propuesta explícita
sobre la que se pide validación, nunca discusiones en curso o notas
personales.

## Roles (cerrado)

`spatie/laravel-permission`, 3 roles fijos — cada usuario tiene **uno solo a
la vez**:

| Rol | Corresponde a (HU-02) |
|---|---|
| `admin` | Administrador |
| `encargado` | Personal de préstamo |
| `usuario` | Solicitante |

Los crea `database/seeders/RoleSeeder.php` de forma idempotente
(`firstOrCreate`), para que existan sin importar el orden en que se corran
los seeders.

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

## Ciclo de préstamos (HU-06, HU-09, HU-11)

Tabla `prestamos`: `usuario_id` (FK a `users`), `equipo_id` (FK a `equipos`), `estado`, `motivo`, `fecha_solicitud`, `fecha_inicio`, `fecha_devolucion_estimada`, `fecha_aprobacion`, `fecha_entrega_real`, `fecha_devolucion_real`, `condicion_entrega`, `condicion_devolucion`, `entregado_por` (FK a `users`), `recibido_por` (FK a `users`), `observaciones_entrega`, `observaciones_devolucion`, `timestamps`.

Decisiones adoptadas en esta rama:
- **Campo `motivo` (cerrado por el PO, Jira KAN-20 / KAN-93):** Obligatorio, texto de hasta 1000 caracteres.
- **Campos diferidos a refinamiento (fuera de alcance en este sprint):** Descripción del préstamo, número de inventario interno, tiempo en horas y consideración por pérdida o falla.
- **Aprobación de préstamos (cerrado):** La aprobación de préstamos va en un PR aparte: HU-08 (KAN-22) y HU-07 (KAN-21), Sprint 2.
- **Actor `recibido_por` (cerrado):** Corresponde al personal administrativo que recibe físicamente la devolución del equipo.
- **Parámetros de negocio en `config/prestamos.php` (cerrado):** 7 días (`duracion_maxima_dias`) y 3 préstamos activos (`max_activos_por_usuario`) quedan como valores por defecto configurables por `.env`.
- **Trazabilidad del préstamo por columnas de fecha y actor:** Sin tabla de historial de préstamos propia. Cada momento queda registrado en sus columnas correspondientes (`fecha_solicitud`, `fecha_aprobacion`, `fecha_entrega_real` + `entregado_por`, `fecha_devolucion_real` + `recibido_por`). Los cambios de estado físico del equipo provocados por la entrega y la devolución quedan auditados automáticamente en `historial_estados` vía `EquipoObserver`.
- **Separación de observaciones y restricción de visibilidad (cerrado, HU-09):** Se separan en dos columnas independientes (`observaciones_entrega` y `observaciones_devolucion`, tipo text, null). Se eliminan prefijos y concatenaciones. En `PrestamoResource`, ambas columnas se exponen únicamente si quien consulta es personal administrativo (`admin` o `encargado`), manteniéndose ocultas para el usuario solicitante. El solicitante sigue viendo `condicion_entrega` y `condicion_devolucion` con sus etiquetas. Los endpoints de entrega y devolución siguen recibiendo el campo `observaciones` en el payload de la petición.
- **Manejo de zona horaria y fechas en la API:** La base de datos y la aplicación Laravel se mantienen en UTC (`app.timezone = 'UTC'`). La zona de negocio se configura en `config('prestamos.zona_horaria')` (`America/Bogota`, UTC-5). La clase `App\Support\FechaNegocio` encapsula el parseo estricto (rechaza fechas sin hora, cadenas relativas o con barras) y la normalización a UTC que consumen los Form Requests. En la salida, `PrestamoResource` serializa las seis fechas de préstamo con copia en memoria hacia la zona de negocio (`-05:00`) sin mutar el modelo en UTC, mientras que `HistorialEstadoResource` y timestamps estándar continúan en UTC (`Z`). Ambos son instantes ISO 8601 estándar.
- **Privacidad de usuarios en recursos de préstamos:** En `PrestamoResource`, `usuario_entrega` y `usuario_recepcion` se restringen siempre a la representación mínima `{ id, name }` (igual que en `HistorialEstadoResource`) para no exponer correo ni roles del personal administrativo a usuarios regulares. En `solicitante`, se expone `{ id, name }` a usuarios regulares y se reservan `email`, `is_active` y `roles` únicamente cuando quien consulta es personal administrativo (`esPersonalAdministrativo()`).
- **Orden de bloqueo único ante concurrencia:** Para evitar interbloqueos (*deadlocks*) y carreras en MySQL 8.0, todo el módulo de préstamos sigue un orden estricto y unidireccional de adquisición de bloqueos pesimistas (`lockForUpdate()`): `usuario -> equipo -> préstamo`. En `SolicitudPrestamoService` se bloquea al solicitante y luego al equipo (para evaluar `max_activos_por_usuario` con datos frescos); en `EntregaPrestamoService` y `DevolucionPrestamoService` se bloquea el equipo y luego el préstamo en `TransicionPrestamoService`.

Ver `docs/api/prestamos.md` para el contrato completo de la API.

