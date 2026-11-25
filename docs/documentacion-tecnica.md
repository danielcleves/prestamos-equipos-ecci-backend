# Documentación técnica

Cómo está construido este backend y por qué. Para quien va a tocar el código:
qué hay en cada carpeta, cómo viaja una petición, dónde viven las reglas, y qué
convenciones hay que respetar para no romper nada.

No repite lo que ya está en otros documentos; los enlaza:

| Documento | Para qué |
|---|---|
| [logica-de-negocio.md](logica-de-negocio.md) | Las reglas del dominio, caso de uso por caso de uso |
| [decisiones.md](decisiones.md) | Decisiones cerradas del equipo y su porqué |
| [api/guia-de-consumo.md](api/guia-de-consumo.md) | Cómo consumir la API desde fuera |
| [api/](api/) | Contrato de cada endpoint |
| [despliegue.md](despliegue.md) | Render + Aiven + GitHub Actions |
| [../README.md](../README.md) | Instalación rápida y flujo de ramas |

---

## 1. Qué es

API REST para gestionar el préstamo de equipos de la universidad: catálogo de
equipos, solicitudes, aprobación, entrega, devolución y revisión del estado del
equipo.

**Es solo backend.** No sirve vistas ni HTML: devuelve JSON y nada más. La
autenticación es por token (Sanctum), no por sesión de navegador.

### Stack

| Pieza | Elección |
|---|---|
| Lenguaje | PHP 8.3 |
| Framework | Laravel 13 (estilo `bootstrap/app.php`, sin `Kernel.php`) |
| Autenticación | `laravel/sanctum` — tokens personales |
| Roles | `spatie/laravel-permission` |
| Base de datos | MySQL 8.0 (producción y validación), SQLite en memoria (pruebas rápidas) |
| Pruebas | PHPUnit |
| Estilo | Laravel Pint |
| Entorno local | Docker Compose |
| Despliegue | Render, base en Aiven, pipeline en GitHub Actions |

Las dependencias de producción son solo cuatro: `laravel/framework`,
`laravel/sanctum`, `laravel/tinker` y `spatie/laravel-permission`. No hay
paquetes de documentación de API, de colas ni de administración.

### Idioma

**El dominio está en español**: tablas, columnas, modelos, estados, variables,
mensajes de error y comentarios. El inglés queda solo donde lo impone Laravel
(`users`, `created_at`, `password`, nombres de métodos del framework).

Si añades código, escríbelo en español. Un `LoanService` junto a
`PrestamoService` haría el proyecto ilegible.

---

## 2. Estructura del repositorio

```
app/
  Enums/                    EstadoPrestamo, CondicionEquipo
  Http/
    Controllers/
      Api/                  4 controladores: Auth, Equipo, Prestamo, User
      Concerns/             RespuestaPaginada (trait compartido)
    Requests/Api/           10 Form Requests: validación de entrada
    Resources/              5 Resources: serialización de salida
  Models/                   6 modelos Eloquent
  Observers/                EquipoObserver (auditoría automática)
  Rules/                    TextoNoVacio, EquipoNoDadoDeBaja
  Services/                 7 servicios: aquí viven las reglas de negocio
  Support/                  FechaNegocio (parseo y zona horaria)
bootstrap/app.php           Rutas, middleware y manejo centralizado de errores
config/prestamos.php        Parámetros de negocio configurables
database/
  migrations/               12 migraciones
  factories/                5 factories
  seeders/                  RoleSeeder, CategoriaSeeder, DatabaseSeeder
docker/                     entrypoint.sh, espera de BD, init de MySQL
docs/                       Esta documentación
lang/es/                    Mensajes y errores en español
routes/api.php              Todas las rutas de la API
tests/
  Unit/                     38 pruebas sin base de datos
  Feature/                  312 pruebas de extremo a extremo
  Support/                  GuardiaBaseDatos
```

---

## 3. Arquitectura: por dónde pasa una petición

```
Ruta (middleware role:)
   └→ Form Request        valida la entrada y la autorización
        └→ Controlador    orquesta; NO contiene reglas de negocio
             └→ Servicio  DB::transaction + bloqueos + reglas
                  └→ TransicionPrestamoService   valida la máquina de estados
                       └→ Modelo / Observer      persistencia + auditoría
             └→ Resource  serializa y oculta campos según el rol
```

Cada capa tiene **una** responsabilidad, y respetarlo es lo que mantiene el
proyecto manejable:

### Rutas — `routes/api.php`

Un solo archivo con todas las rutas, agrupadas por middleware. La autorización
por rol se resuelve aquí (`role:admin`, `role:admin|encargado`), no dentro de
los controladores.

> **Trampa conocida:** las rutas literales van **antes** que las paramétricas.
> `/prestamos/activos` y `/prestamos/pendientes` están registradas antes de
> `/prestamos/{prestamo}`; invertirlas haría que `activos` se interpretara como
> un id. Si añades una ruta literal bajo `/prestamos`, colócala arriba.

### Form Requests — `app/Http/Requests/Api/`

Validan **forma**, no reglas de negocio: que el campo exista, que sea del tipo
correcto, que la fecha tenga un formato admitido. Además normalizan: varios
sobrescriben `validated()` para devolver las fechas ya convertidas a `Carbon` en
UTC, de modo que el servicio recibe objetos y no cadenas.

### Controladores — `app/Http/Controllers/Api/`

Deliberadamente delgados: reciben el request, llaman al servicio, cargan las
relaciones y devuelven el Resource. **Si te encuentras escribiendo un `if` de
negocio en un controlador, va en un servicio.**

Tres de los cuatro (`Equipo`, `Prestamo` y `User`) comparten el trait
`RespuestaPaginada`, que es el contrato único de los listados: acota `per_page` a
`[1, 100]` y arma el bloque `meta`. Antes estaba copiado en cada listado.
`AuthController` no lo usa porque no pagina nada.

### Servicios — `app/Services/`

Donde viven las reglas. Siete:

| Servicio | Qué decide |
|---|---|
| `SolicitudPrestamoService` | Cupo, disponibilidad y solapamiento de fechas |
| `AprobacionPrestamoService` | Revalida el equipo con datos frescos |
| `RechazoPrestamoService` | Motivo obligatorio |
| `EntregaPrestamoService` | Entrega y paso del equipo a `en_prestamo` |
| `DevolucionPrestamoService` | Devolución, revisión y destino del equipo |
| `RevisionEquipoService` | Registro de la revisión (HU-12) |
| `TransicionPrestamoService` | **El único** que cambia `prestamos.estado` |

Todos abren `DB::transaction`. Los cuatro de caso de uso orquestan a
`TransicionPrestamoService` añadiendo su propia validación de equipo y sus
columnas.

### Resources — `app/Http/Resources/`

Serializan y **deciden qué ve cada rol**. La visibilidad de campos sensibles no
está en el controlador ni en la consulta: está aquí, con `when()`.

---

## 4. Las reglas que no se negocian

Cuatro invariantes que sostienen el proyecto. Romper cualquiera introduce bugs
difíciles de ver.

### 4.1 Orden de bloqueo único

Todos los servicios adquieren bloqueos pesimistas (`lockForUpdate()`) en un
**único orden unidireccional**:

```
usuario → equipo → préstamo
```

Es lo que evita interbloqueos en MySQL cuando dos peticiones compiten por el
mismo equipo o por el cupo del mismo usuario.

> **Cuidado:** `lockForUpdate()` **no bloquea en SQLite**. Las carreras solo se
> reproducen contra MySQL, y ninguna de las dos suites cubre concurrencia HTTP
> real. La protección vive en el diseño, no en los tests. Si añades un servicio
> que bloquee filas, respeta ese orden.

### 4.2 `TransicionPrestamoService` es el único que cambia de estado

El mapa de transiciones válidas está centralizado en `EstadoPrestamo`, en un
solo `match`. Ningún otro punto escribe `prestamos.estado`.

Si añades un estado nuevo, lo añades ahí y el resto del sistema lo respeta solo.

### 4.3 La auditoría del equipo es automática

`EquipoObserver` escribe en `historial_estados` cada vez que cambia
`equipos.estado`, incluida la asignación inicial. Está en un observer justamente
para que **cualquier código futuro quede cubierto sin acordarse de loguear**.

No escribas `historial_estados` a mano.

### 4.4 Las fechas pasan por `FechaNegocio`

La aplicación y la base viven en **UTC**; la zona de negocio es `America/Bogota`
(UTC-5). `App\Support\FechaNegocio` encapsula:

- **Parseo estricto**: rechaza fechas sin hora, con barras o relativas.
- **Normalización**: lo que llega sin zona se interpreta en hora de Colombia y
  se guarda en UTC.
- **Salida**: las fechas de préstamos y revisiones se serializan en `-05:00`
  sobre una **copia**, sin mutar el modelo.

No uses `Carbon::parse()` directamente sobre entrada del usuario.

---

## 5. Modelo de datos

```
Categoria 1──N Equipo 1──N Prestamo N──1 User (solicitante)
                 │            │     ╲
                 │            │      ╲── User (gestionado_por / entregado_por / recibido_por)
                 │            │
                 │            └──1 RevisionEquipo ──1 User (revisado_por)
                 │
                 ├──N HistorialEstado ──1 User
                 └──N RevisionEquipo
```

| Tabla | Papel |
|---|---|
| `categorias` | Agrupa equipos. **Sin CRUD**: solo seeder |
| `equipos` | Unidad física individual, `codigo` único |
| `prestamos` | Ciclo de vida completo, con fecha y actor por hito |
| `revisiones_equipo` | Revisión en la devolución. Una por préstamo |
| `historial_estados` | Auditoría de cambios de estado **del equipo** |
| `users` | Con rol de Spatie e `is_active` |

Las FK desde `prestamos` hacia `users` y `equipos` son `restrictOnDelete`: no se
borra un usuario o un equipo que tenga préstamos. Las de actores de gestión son
`nullOnDelete`.

### Dos máquinas de estado

**Equipo** — 4 estados. `disponible` es el inicial y el único prestable;
`dado_de_baja` es **terminal**.

**Préstamo** — 6 estados:

```
                    ┌─→ rechazado (final)
  solicitado ───────┼─→ cancelado (final)
                    └─→ aprobado ──┬─→ cancelado (final)
                                   └─→ entregado ──→ devuelto (final)
```

`cancelado` es alcanzable en el modelo pero **todavía no tiene endpoint**.

### Parámetros configurables — `config/prestamos.php`

| Parámetro | Default | Variable de entorno |
|---|---|---|
| `duracion_maxima_dias` | 7 | `PRESTAMO_DURACION_MAXIMA_DIAS` |
| `max_activos_por_usuario` | 3 | `PRESTAMO_MAX_ACTIVOS_POR_USUARIO` |
| `zona_horaria` | `America/Bogota` | `PRESTAMO_ZONA_HORARIA` |

No los escribas a mano en el código: léelos de la configuración.

---

## 6. Autorización

Tres roles fijos (`admin`, `encargado`, `usuario`), **uno por persona**,
sembrados por `RoleSeeder`.

El pivote real no es el rol individual sino **personal administrativo** =
`admin` o `encargado`, en `User::esPersonalAdministrativo()`. Es lo que consultan
los Resources y la visibilidad de los listados.

> La matriz de permisos granulares (`prestamos.aprobar`, etc.) está **propuesta
> pero no implementada** en `decisiones.md`. Hoy todo es autorización por rol vía
> middleware. No generes `Permission::create(...)` a partir de ese borrador sin
> consultarlo con el equipo.

### Ocultar en vez de prohibir

Cuando un recurso **existe pero no te corresponde**, la API responde `404`, no
`403`. Un préstamo ajeno y un id inexistente devuelven respuestas idénticas. Es
deliberado: un `403` confirmaría la existencia del recurso.

Implementado lanzando `ModelNotFoundException` desde el controlador.

---

## 7. Errores

Centralizados en `bootstrap/app.php`. Ningún controlador arma respuestas de
error a mano.

| Código | Origen |
|---|---|
| `401` | `AuthenticationException` |
| `403` | `AuthorizationException`, `UnauthorizedException` de Spatie |
| `404` | `ModelNotFoundException`, `NotFoundHttpException` |
| `405` | `MethodNotAllowedHttpException` |
| `422` | `ValidationException` (validación **y** reglas de negocio) |
| `429` | `ThrottleRequestsException` |
| `500` | Solo con `app.debug = false`, con mensaje genérico |

Los mensajes salen de `lang/es/errores.php`.

**Las violaciones de regla de negocio se expresan como `ValidationException`**,
con la clave del campo responsable, no como excepciones propias de dominio. Así
el cliente pinta `errors` y cubre ambos casos con el mismo código.

Un detalle que costó encontrar: `redirectGuestsTo(fn () => null)` está ahí
porque, sin eso, el middleware `Authenticate` buscaba una ruta `login`
inexistente y devolvía 500 en lugar de 401.

---

## 8. Pruebas

**376 pruebas, 1696 aserciones.** 38 unitarias y 312 de feature.

### Dos motores, a propósito

```bash
composer test          # SQLite en memoria — ciclo rápido de desarrollo
composer test:mysql    # MySQL 8.0 — OBLIGATORIO antes de abrir un PR
```

SQLite no replica bloqueos pesimistas, índices, dialecto SQL ni el manejo
estricto de tipos y FK de MySQL. **Pasar en SQLite no es suficiente.**

### La guardia que protege tu base local

`GuardiaBaseDatos` se engancha en `TestCase::setUpTraits()` y aborta la suite si
la conexión es MySQL y la base no termina en `_test`, o si es SQLite y no está en
memoria.

Existe porque `RefreshDatabase` borra la base en cada corrida: sin esta guardia,
una variable de entorno mal puesta vaciaría la base de desarrollo. Por eso
`phpunit.xml` usa `force="true"` en cada variable — dentro de Docker, las
variables del contenedor tienen prioridad y sin forzar se correrían las pruebas
contra MySQL de desarrollo.

> **No ejecutes dos corridas de `test:mysql` en paralelo** (dos terminales, o
> una terminal y un agente). Comparten la base `prestamos_test` y la recrean al
> iniciar: colisionan con errores de tabla inexistente o deadlocks en pruebas
> que normalmente pasan limpias.

### Pruebas de N+1

Varias pruebas cuentan consultas con `DB::listen` y fallan si el número crece.
No son decorativas: así se detectó que `PrestamoResource` hacía una consulta por
fila al exponer los roles del solicitante.

> **Si añades un campo de relación a un Resource, añade la relación a
> `RELACIONES_RECURSO`.** Es el error más fácil de cometer en este código.

---

## 9. Entorno local

```bash
docker compose up -d
docker compose exec prestamos_backend php artisan db:seed
```

Dos contenedores: `prestamos_backend` (PHP 8.3 en el puerto 8000) y
`prestamos_db` (MySQL 8.0 en el **3307** del host, para no chocar con un MySQL o
XAMPP ya instalado, y ligado a `127.0.0.1` para no exponerlo a la red local).

El `entrypoint.sh` hace, en orden: instala `vendor/` si falta, prepara el `.env`,
genera `APP_KEY`, crea los directorios escribibles, **espera a que MySQL responda**
y corre las migraciones.

Dos detalles con historia detrás:

- La **espera de la base** está en el entrypoint y no solo en `depends_on`,
  porque `service_healthy` solo aplica en `docker compose up`: en un `restart` o
  tras reiniciar la máquina no se respeta.
- `docker-compose.yml` expone **solo** `DB_HOST` y `DB_PORT` al contenedor. Está
  comentado en el archivo y conviene respetarlo: Docker publica esas variables
  en `$_SERVER`, que PHP lee con más prioridad que `phpunit.xml`, así que
  cualquier variable de base de datos que añadas ahí haría que las pruebas
  corran contra la base de desarrollo.

### Comandos

| Comando | Para qué |
|---|---|
| `composer test` | Suite rápida (SQLite) |
| `composer test:mysql` | Suite contra MySQL |
| `php vendor/bin/pint` | Formatea |
| `php vendor/bin/pint --test` | Comprueba sin tocar |
| `php artisan migrate:fresh --seed` | Base limpia |

---

## 10. CI/CD

`.github/workflows/ci-cd.yml`:

- **Pull request** hacia `develop` o `main` → corre las pruebas, no despliega.
- **Push** a `develop` o `main` → pruebas y, si pasan, dispara el deploy hook de
  Render de esa rama.

El pipeline corre **las dos suites**: SQLite y MySQL 8.0 como servicio.

Decisiones del workflow que conviene no deshacer:

- **El auto-deploy de Render está desactivado**: el único que despliega es el
  workflow, y solo después de que las pruebas pasen.
- Las actions están **fijadas por SHA**, no por tag, para que un tag movido no
  cambie el código que corre en el pipeline.
- Los cambios que solo tocan `*.md` o `docs/` **no** disparan el workflow.
- El deploy hook se llama con `&ref=${GITHUB_SHA}` para que Render despliegue
  exactamente el commit que pasó las pruebas.

Detalle completo en [despliegue.md](despliegue.md).

---

## 11. Convenciones para contribuir

1. **Español** en todo el dominio.
2. **Las reglas van en servicios**, no en controladores ni en modelos.
3. **Una transacción por caso de uso**, con el orden de bloqueo
   `usuario → equipo → préstamo`.
4. **Fechas por `FechaNegocio`**, nunca `Carbon::parse()` sobre entrada cruda.
5. **Errores de negocio como `ValidationException`** con la clave del campo.
6. **Rutas literales antes que paramétricas.**
7. **Relación nueva en un Resource → añadirla a `RELACIONES_RECURSO`.**
8. **Pint antes de commitear**, y `composer test:mysql` antes del PR.
9. **Documenta la decisión**, no solo el código: si tomas una decisión de
   diseño, anótala en `decisiones.md` con su porqué.

### Comentarios

El código está comentado explicando **por qué**, no qué. Los comentarios que
dicen "esto está así porque sin ello pasaba X" valen oro y no deben borrarse al
refactorizar: casi todos documentan un bug que ya se pagó una vez.

---

## 12. Estado y deuda conocida

### Implementado

| HU | Tema |
|---|---|
| HU-01 | Autenticación |
| HU-02 | Usuarios y roles |
| HU-03 / HU-04 | Catálogo y cambio de estado con historial |
| HU-05 | Catálogo con filtros y búsqueda |
| HU-06 | Solicitud de préstamos |
| HU-07 | Solicitudes pendientes |
| HU-08 | Aprobación y rechazo |
| HU-09 | Entrega |
| HU-10 | Consulta de préstamos propios y filtro de activos |
| HU-11 | Préstamos activos y devolución |
| HU-12 | Revisión del equipo en la devolución |

### Pendiente

1. **Cancelación de préstamos.** `cancelado` es alcanzable en la máquina de
   estados pero no hay endpoint, ni columnas, ni actor. El hueco más visible.
2. **Detección de retrasos.** Nada compara `fecha_devolucion_estimada` con la
   fecha actual. Los índices de `prestamos` ya se crearon pensando en esto.
3. **HU-31 incidencias.** `revisiones_equipo.incidencia_id` existe, indexado y
   **sin FK**, esperando la tabla. Al crearla, añadir la foreign key.
4. **Evidencias** (fotos de la condición). Decidido, sin implementar.
5. **CRUD de categorías.** No existe; tres fijas por seeder.
6. **Permisos granulares.** La matriz sigue siendo propuesta sin validar.
7. **Renovaciones/prórrogas** y **mantenimientos** como tabla propia.

### Una deuda que conviene tener presente

Las columnas `condicion_devolucion`, `observaciones_devolucion` y `recibido_por`
de `prestamos` **duplican** datos de `revisiones_equipo`. Fue deliberado, para
no romper el contrato de la API al introducir la entidad, y la revisión es la
fuente de verdad. Pero son dos sitios que describen el mismo hecho y pueden
divergir.

**Toda escritura debe pasar por `DevolucionPrestamoService`**; nunca actualices
esas columnas a mano. El razonamiento completo está en `decisiones.md`.
