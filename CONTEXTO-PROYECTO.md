# Contexto del proyecto — Sistema de Préstamo de Equipos (Backend)

> Este documento existe para que cualquier asistente de IA (Claude Code u otro)
> entienda el proyecto sin necesidad de que se le re-explique cada vez. Pegalo
> como contexto al inicio de cualquier sesión de trabajo sobre este repo, o
> dejalo en la raíz del repo como `CONTEXTO-PROYECTO.md` para que quien lo abra
> (humano o IA) tenga el panorama completo en un solo lugar.
>
> **Este archivo está en `.gitignore` a propósito:** documenta discusiones
> internas y decisiones aún sin cerrar, así que no viaja al repositorio
> compartido. Vive solo en la máquina de Jose. Si trabajas desde otro equipo,
> hay que copiarlo a mano (guardar copia en Drive del equipo).
>
> **Última actualización:** se incorporó el documento oficial del equipo
> ("Proyecto préstamo de Equipos - Entrega y verificación 1"), que resuelve
> varios pendientes y also contradice/matiza cosas que estaban acá. Ver
> sección 0 para el resumen de cambios.

---

## 0. Cambios de esta actualización (leer primero)

Comparando este documento con el entregable oficial del equipo, aparecieron
estos hallazgos:

### Confirmado / resuelto
- **Frontend = Blade, no Inertia+Vue.** El equipo decidió Laravel + Blade,
  repo independiente, consumiendo la API (no accede a la BD directamente).
  Se descarta Filament explícitamente por falta de libertad de personalización
  frente a los mockups de Figma. Con Blade puro server-to-server, CORS **no
  se activa** en la práctica (queda configurado "por si acaso" nomás).
- **Ya existe un backlog completo:** 24 historias de usuario (HU-01 a HU-24)
  con criterios de aceptación, redactadas por el equipo (Emmanuel como PO).
  Ya no hay que pedirle historias al PO — hay que **validar** que las HU-01 a
  HU-05 (asignadas a Sprint 1) coincidan con lo que se va a implementar.
- **Arquitectura API separada confirmada** por Frontend, Backend (José David)
  y Líder Técnico (Daniel) — ver sección 3.

### Pendiente de aclarar con el equipo (no asumir, no cerrar solo)
- **Rol real en DevOps.** La matriz de roles del documento dice que **Victor
  Marín es el rol de DevOps** (despliegues y observabilidad), y que tu rol
  (Backend) solo *"apoya a DevOps en despliegues automatizadas en caso de ser
  necesario"*. Esto es distinto de cómo estaba planteado acá (vos como
  Backend+DevOps dueño de ambas partes). No implica que el trabajo de Docker
  ya hecho esté mal — pero hay que aclarar quién lidera CI/CD, Railway, etc.
  de acá en adelante.
- **Duración del sprint inconsistente.** Este documento decía sprints de 1
  semana (~12 semanas totales). El plan de "primera iteración - Sprint 1" del
  equipo dice **"Duración: 2 semanas"**. Hay que confirmar cuál es la
  cadencia real.
- **Contradicción interna en el documento del equipo (avisarles):** en la
  sección de arquitectura, la decisión dice "Backend y Frontend separados, en
  dos repositorios", pero el párrafo "Por Qué" que sigue justifica lo
  contrario (monolito: "una sola base de código... Laravel cubre backend y
  frontend con vistas Blade"). Parece un error de copiar/pegar del equipo. La
  sección "Componentes Preliminares" del mismo documento sí confirma repos
  separados, así que se toma esa como la versión válida — pero hay que
  pedirles que corrijan el documento.

### Modelo de datos: referencia nueva del equipo
El documento oficial menciona solo 3 tablas base: `equipos`, `prestamos`,
`usuarios` (con sus estados). Es mucho más chico que la propuesta de 12
tablas de este archivo (sección 4). Se usa como ancla para la reducción, pero
**sigue sin haber versión final acordada** — ver sección 4.

---

## 1. Qué es esto

Backend en Laravel de un **sistema de préstamo de equipos**, proyecto
universitario de gestión de software. Arquitectura **API-first**: este backend
no sirve vistas, expone una API REST consumida por un frontend en otro repo
separado.

**Actualización:** el frontend ya no está "sin decidir" — es **Laravel +
Blade** (compañero: Jose López), repo independiente, consumiendo la API.

## 2. Equipo y mi rol

Trabajo bajo metodología **Scrum**, ~12 semanas totales (duración de sprint
por confirmar: 1 semana según este doc, 2 semanas según el plan de Sprint 1
del equipo — ver sección 0).

**Roles y responsabilidades oficiales (del documento del equipo):**

| Miembro | Rol | Responsabilidades |
|---|---|---|
| Emmanuel Valencia | PO / Analista / Gestor / Delivery | Priorizar backlog, definir visión y requisitos, planear iteraciones, coordinar equipo y entregas |
| Daniel Cleves | Líder Técnico | Arquitectura, decisiones técnicas, repo y tablero, soporte a Emmanuel |
| Alejandro Molina | UX/UI Designer | Maquetación y diseño en Figma |
| Jose López | Desarrollo Frontend | Implementar interfaz según Figma (Laravel + Blade) |
| **José David (yo)** | **Desarrollo Backend** | **Lógica de negocio, API/rutas y datos; apoyo a DevOps en despliegues** |
| Sebastián Rodríguez | QA y Automatización de Pruebas | Pruebas con PHPUnit, calidad del producto |
| Victor Marín | **DevOps**, Despliegue y Observabilidad | Despliegues automatizados y monitoreo; apoya a QA si lo necesita |

Acuerdos de apoyo entre roles (reunión 26 ago):
- Backend (yo) apoya a DevOps (Victor) en despliegues automatizados si hace falta.
- DevOps apoya a QA si Sebastián lo necesita.
- Líder Técnico apoya al PO en lo que necesite.

Reglas de convivencia importantes:
- Las historias de usuario y el backlog son responsabilidad del PO. **Ya
  existen 24 historias (HU-01 a HU-24) redactadas por Emmanuel** — no hace
  falta que una IA me ayude a borradorearlas, pero sí puede ayudarme a
  entenderlas o detectar ambigüedades para preguntarle al PO.
- Decisiones con impacto en todo el equipo (arquitectura, matriz de permisos,
  Definition of Done, quién lidera DevOps) se confirman con el equipo antes
  de darlas por cerradas, aunque yo ya las tenga técnicamente resueltas de mi
  lado.

## 3. Decisiones técnicas ya cerradas (no rediscutir sin razón explícita)

| Tema | Decisión |
|---|---|
| Framework | Laravel, última estable |
| Arquitectura | **API separada del frontend, no monolito** — confirmado por Frontend, Backend y Líder Técnico. (Nota: el documento del equipo tiene una contradicción de redacción en la sección "Por Qué"; avisar para que la corrijan — ver sección 0) |
| Frontend | **Laravel + Blade** (no Inertia+Vue), repo independiente, consume la API, NO accede directo a la BD. Filament descartado por falta de libertad de personalización frente a mockups Figma |
| Auth | Sanctum en **modo API tokens (Bearer)** — no modo SPA/cookies |
| CORS | Habilitado en `config/cors.php` por si acaso, pero con Blade puro server-to-server **no se usa activamente** — no hace falta quitarlo |
| Roles/permisos | `spatie/laravel-permission`, 3 roles: `admin`, `encargado`, `usuario` — **también en `docs/decisiones.md` (sí viaja en el repo)** |
| Base de datos | MySQL 8.0 (no PostgreSQL) |
| Convención de tablas | `users` (no `usuarios`, por convención Laravel) |
| Idioma de la API | **Español neutro en toda la API** (`APP_LOCALE=es`), traducciones manuales en `lang/es/` sin paquetes externos, mensajes HTTP en `lang/es/errores.php` |
| Formato de error de API | `{ "message": "..." }` para errores HTTP generales y `{ "message": "...", "errors": {...} }` para validaciones 422 — consistente en toda la API (ver `docs/api/errores.md`) |
| Ramas | `main` (protegida, PR + review obligatorio), `develop` (integración), `feature/*` **por tarea, no por proyecto** |
| Entorno local | Todo dockerizado: backend y MySQL como servicios de `docker-compose.yml`, mismo repo. Un dev nuevo solo necesita Docker Desktop |
| Secretos | Nunca en `docker-compose.yml`. Se interpolan desde `.env` (ignorado por git) con `${VAR}` |
| QA | PHPUnit (herramienta predeterminada de Laravel), a cargo de Sebastián |

## 4. Modelo de datos — CERRADO por el PO el 2026-09-05

✅ **Ya no está en discusión.** El PO (Emmanuel) mandó la tabla de "Alcance
funcional definitivo" el 5 de septiembre, que resuelve el debate de 12 vs 3
tablas que llevaba abierto desde el inicio del proyecto:

| Tabla / funcionalidad | Decisión | Nota |
|---|---|---|
| `categorias` | Incluir | Agrupa equipos por tipo (portátil, tablet, de mesa...) |
| `unidades_equipo` | No va | Redundante: cada equipo tiene su propio código/serial, se gestiona individual |
| `renovaciones` | Incluir | Prorrogar/devolución: editar fecha fin del préstamo |
| `evidencias` | Incluir | Fotos/URLs de condición al entregar y devolver |
| `notificaciones` | No va | Sin uso real; solo alerta de no-devolución en dashboard |
| `mantenimientos` | Diferido | Baja prioridad, último sprint si alcanza |
| `lista_espera` | En espera | Pendiente de consultar con el profesor (cliente) |
| `parametros_sistema` | Opcional | Sin decisión explícita todavía |
| `historial_estados` | Se mantiene | Trazabilidad/auditoría de cambios de estado |

Esta tabla **reemplaza** la propuesta de 12 tablas mía y la de 3 tablas del
documento oficial — ya no hay que reconciliarlas, esta es la versión final.
**También quedó copiada en `docs/decisiones.md`** (versionado, sí viaja en
el repo) para que el resto del equipo la tenga sin necesitar este archivo.

Las tablas `roles`, `permissions`, `model_has_roles`, `model_has_permissions`,
`role_has_permissions` las genera automáticamente `spatie/laravel-permission`
— no se crean a mano.

**Ya implementado:** `categorias` y `equipos` (HU-03, sección 18);
`historial_estados` (HU-04, sección 19).

**Pendiente de implementar cuando llegue su HU:** `renovaciones`,
`evidencias`, `prestamos`. `mantenimientos`, `lista_espera` y
`parametros_sistema` siguen sin decisión firme — no generar sus migraciones
todavía.

## 5. Matriz de permisos (Spatie) — propuesta, no validada aún por el equipo

**Esta matriz también vive en `docs/decisiones.md` (versionado, sí viaja en
el repo) — mantener las dos en sync si se toca una.**

⚠️ Puede cambiar si el modelo de datos se recorta (por ejemplo, permisos
sobre `mantenimientos` no aplicarían si esa tabla se elimina). No tratar como
definitivo. Sí es relevante para **HU-01** (login con restricción de
funcionalidades por rol), que está en Sprint 1 — ver sección 7.

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
- `encargado`: unidades.*, prestamos.ver_todos/aprobar/rechazar/entregar/recibir, mantenimientos.gestionar, equipos.ver
- `usuario`: equipos.ver, prestamos.crear, prestamos.ver_propios

## 6. Reglas de negocio pendientes de confirmar con el PO

Ninguna lógica que dependa de estos valores debe hardcodearse hasta tener
respuesta. Usar `parametros_sistema` (tabla clave-valor) para lo configurable.

1. ¿Máximo de préstamos activos es igual para todos o varía por tipo de usuario?
2. ¿Duración default de un préstamo? (sugerido: 7 días, sin confirmar)
3. ¿Cuánto tiempo antes de expirar en `lista_espera`?
4. ¿Hay límite de renovaciones por préstamo?
5. ¿Evidencias (fotos) son obligatorias siempre o solo si hay daño reportado?

## 7. Backlog e historias de usuario (NUEVO — ya existen, redactadas por el PO)

El equipo ya tiene 24 historias de usuario (HU-01 a HU-24) con épica,
prioridad, criterios de aceptación y estimación en story points. Cubren:
gestión de usuarios (HU-01, HU-02), gestión de equipos (HU-03 a HU-05,
HU-22), solicitud de préstamos (HU-06 a HU-08, HU-18, HU-20, HU-21), entrega
(HU-09, HU-10), devolución (HU-11, HU-12), control de retrasos (HU-13, HU-14),
historial y trazabilidad (HU-15, HU-16, HU-23, HU-24), reportes (HU-17, HU-19).

**Historias asignadas a Sprint 1** (objetivo: autenticación + gestión básica
de equipos):

| ID | Historia | Prioridad | Estimación |
|---|---|---|---|
| HU-01 | Iniciar sesión según rol del usuario | Alta | 3 SP |
| HU-02 | Gestionar usuarios y roles | Alta | 5 SP |
| HU-03 | Registrar equipos en el sistema | Alta | 5 SP |
| HU-04 | Actualizar el estado de los equipos | Alta | 3 SP |
| HU-05 | Consultar los equipos disponibles | Alta | 3 SP |

**Criterios de aceptación de HU-01 (login)**, para referencia al implementar:
el usuario ingresa credenciales, el sistema las valida, permite acceso si son
correctas, muestra error si no, **restringe funcionalidades según el rol**,
permite cerrar sesión.

Esta historia no depende de que se resuelva el debate del modelo de datos de
12 vs 3 tablas: usa `users` (convención Laravel, ya cerrado en sección 3) y
los 3 roles de Spatie (`admin`, `encargado`, `usuario`, ya cerrados en
sección 3). Se puede avanzar con Sprint 1 sin bloquearse por el pendiente de
la sección 4.

## 8. Endpoints de soporte — definidos en papel, NO implementados

⚠️ Los de la tabla siguiente son un diseño acordado, **ninguno existe todavía
en el código**. Lo único implementado hoy es:

| Endpoint | Estado |
|---|---|
| `GET /api/ping` | ✅ implementado, devuelve `{"status":"ok"}` — sirve al frontend para validar conectividad |
| `GET /up` | ✅ healthcheck nativo de Laravel |

Pendientes de implementar:

| Endpoint | Método | Propósito |
|---|---|---|
| /api/health | GET | Estado del servicio + conexión BD (DevOps) |
| /api/reportes/resumen | GET | Préstamos activos, equipos más solicitados, atrasos |
| /api/prestamos/{id}/renovar | PATCH | Solicitar extensión de fecha |
| /api/lista-espera | POST | Unirse a la cola |
| /api/docs | GET | Documentación (Swagger/OpenAPI vía l5-swagger) |

## 9. Reglas de negocio a validar en `PrestamoService`

- Máximo de préstamos activos por usuario (según `parametros_sistema`)
- No permitir solapamiento de fechas sobre la misma unidad
- Formato de error consistente en toda la API
- Rate limiting en `/api/login` y `/api/prestamos` (throttle middleware)

## 10. Entorno de desarrollo local (funcionando y verificado)

Todo dockerizado. Un desarrollador nuevo **no necesita PHP, Composer ni MySQL
instalados**, solo Docker Desktop:

```
git clone <repo> && cd prestamos-equipos-ecci-backend
cp .env.example .env
docker compose up -d --build
```

Verificado clonando el repo en limpio: levanta, migra y responde `/api/ping`.
El primer build tarda varios minutos; los siguientes, segundos.

### Archivos

| Archivo | Rol |
|---|---|
| `Dockerfile` | php:8.3-cli + extensiones. Corre como usuario `www`, **no root** |
| `docker/entrypoint.sh` | Prepara el entorno en cada arranque |
| `docker-compose.yml` | Servicios `prestamos_backend` y `prestamos_db` |
| `.dockerignore` | Excluye `vendor`, `node_modules`, `.env` y cachés |

Extensiones PHP: `pdo_mysql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`
(compilado **con JPEG y FreeType**, no solo PNG), `zip`. Composer fijado en
`2.8` para builds reproducibles. La capa de dependencias va separada del
código: cambiar un `.php` no reinstala `vendor/`.

### Qué hace el entrypoint en cada arranque

1. Instala dependencias si falta `vendor/autoload.php`
2. Crea `.env` desde `.env.example` si no existe
3. Genera `APP_KEY` si falta
4. Crea los directorios escribibles de `storage/` y `bootstrap/cache`
5. **Espera activa a MySQL** vía PDO (60 intentos, 2s)
6. Aplica migraciones (`migrate --force`, idempotente)

El punto 5 es necesario porque `depends_on: service_healthy` **solo aplica en
`docker compose up`**. En un `restart`, un `docker start` o tras reiniciar la
máquina no se respeta, y sin la espera el backend crashea en bucle contra una
BD que aún no acepta conexiones.

### Puertos

| Servicio | En el contenedor | En el host | Variable para cambiarlo |
|---|---|---|---|
| backend | 8000 | 8000 | `APP_PORT` |
| MySQL | 3306 | **3307**, solo en `127.0.0.1` | `DB_PORT_HOST` |

MySQL se publica en 3307 para no chocar con un XAMPP/MySQL local en 3306, y
atado a `127.0.0.1` para que no quede expuesto en la red del salón/casa. Los
puertos del host son configurables por `.env`: si un compañero tiene el 8000
ocupado, lo cambia en su `.env` **sin tocar el `docker-compose.yml` compartido**.

Los nombres de servicio `prestamos_backend` y `prestamos_db` son fijos porque
el frontend (otro repo) los referencia.

### Credenciales — de dónde salen

`docker-compose.yml` **no contiene ninguna contraseña**. Usa marcadores que
Docker Compose interpola desde el `.env` de la carpeta:

```
.env (en disco, NUNCA en git)
   -> ${DB_PASSWORD} en docker-compose.yml
      -> MYSQL_PASSWORD (MySQL crea el usuario)
      -> DB_PASSWORD    (Laravel se conecta)
```

Por eso nunca se desincronizan: salen de la misma línea del mismo archivo.
Las contraseñas usan `${DB_PASSWORD:?mensaje}` **sin valor por defecto**: si
falta, `docker compose` falla con un mensaje claro en vez de arrancar con una
clave débil silenciosa. Cada dev tiene la suya. `.env.example` trae marcadores
que funcionan en local y que hay que cambiar.

### Volúmenes — el detalle que rompía todo

| Volumen | Para qué |
|---|---|
| `.:/var/www` | bind mount, código en caliente |
| `/var/www/vendor` (anónimo) | **impide que el bind mount tape el `vendor/` de la imagen** |
| `/var/www/node_modules` (anónimo) | igual para npm |
| `prestamos_db_data` | persiste MySQL |

Sin los volúmenes anónimos, el bind mount monta la carpeta del host sobre
`/var/www` y **oculta el `vendor/` que instaló el build**. En una máquina que
ya tenía dependencias locales funcionaba por accidente; en un clon limpio
fallaba con `Failed opening required 'vendor/autoload.php'`.

### Comandos útiles

```
docker compose up -d --build      # levantar (build la 1a vez o al cambiar Dockerfile)
docker compose logs -f prestamos_backend
docker compose exec prestamos_backend php artisan <lo que sea>
docker compose exec prestamos_backend php artisan test
docker compose restart            # tras un git pull con migraciones nuevas
docker compose down               # bajar (conserva la BD)
docker compose down -v            # bajar Y BORRAR la base de datos
```

### Cada desarrollador tiene su PROPIA base de datos

Quien clone este repo levanta **su propio contenedor de MySQL, con su propio
volumen y sus propios datos**. No hay una base de datos compartida entre el
equipo en desarrollo local.

- **Seeders** (`database/seeders`) versionados en el repo, que cada uno corre
  con `php artisan db:seed`. Es la vía normal en desarrollo.
- **Un entorno compartido** (Railway) cuando se necesite una base común para
  demos o integración con el frontend.

### Nota de integración con Frontend

El frontend (Jose López, Laravel + Blade) está en **otro repositorio** y no
debe levantar su propio servicio de MySQL: la base de datos vive en el
servicio `prestamos_db` de este repo. El frontend consume la API vía HTTP
(server-to-server, ya que es Blade puro) en `http://localhost:8000` (o el
`APP_PORT` que cada dev tenga en su `.env`).

Los nombres de servicio `prestamos_backend` y `prestamos_db` son fijos: si el
frontend arma un compose de integración, los referencia por esos nombres.

## 11. CI/CD y paso a producción

- Pipeline separado del frontend (`.github/workflows/ci.yml`) — **aún no creado**
- Tests con MySQL de servicio en cada push/PR a `develop`/`main`
- Deploy sugerido: Railway (activar recién cuando se necesite un entorno
  compartido para demo/integración — no antes, el trial es limitado)
- **Pendiente aclarar:** quién lidera esta parte de acá en adelante — ver
  sección 0 (Victor es DevOps oficial; yo apoyo si hace falta)

### El setup actual es SOLO de desarrollo

| | Hoy (desarrollo) | Producción |
|---|---|---|
| Servidor | `artisan serve`, 1 request a la vez | FrankenPHP o nginx + php-fpm |
| Código | bind mount desde el disco | horneado en la imagen, sin mounts |
| Dependencias | incluye phpunit, faker | `--no-dev --optimize-autoloader` |
| OPcache | desactivado | activado, `validate_timestamps=0` |
| `APP_DEBUG` | `true` | `false` |
| `APP_ENV` | `local` | `production` |
| Secretos | archivo `.env` | inyectados por la plataforma |
| Migraciones | automáticas al arrancar | paso separado del despliegue |

### Dos trampas que destruyen datos (no olvidar)

1. **`APP_KEY` no se puede generar al arrancar en producción.** En prod se
   genera UNA vez y se guarda como secreto de la plataforma.
2. **`config:cache` debe correr al arrancar, no al construir.**

Además: `migrate --force` en cada arranque es cómodo en local, pero con varias
réplicas dos contenedores migran a la vez y corrompen el estado. En producción
va como paso explícito del despliegue.

### Antes de desplegar, además

- Cerrar `allowed_origins` en `config/cors.php`: hoy está en `'*'`
- Backups de la base de datos

## 12. Plan de sprints

| Semana | Sprint | Foco |
|---|---|---|
| 1(-2?) | Sprint 0/1 | Setup + Auth (Sanctum) + CRUD equipos/categorías. Ver nota de duración en sección 0 |
| ... | Sprint 2 | Flujo de préstamos (solicitar → aprobar → entregar) |
| ... | Sprint 3 | Devoluciones, historial_estados, notificaciones |
| ... | Sprint 4 | Renovaciones, lista_espera, evidencias |
| ... | Sprint 5 | Reportes, parámetros_sistema, endpoints de soporte |
| ... | Sprint 6 | Integración con frontend, ajustes, QA a fondo |
| ... | Cierre | Buffer, documentación final, demo |

⚠️ Numeración de semanas por confirmar hasta resolver la duración real del
sprint (1 vs 2 semanas — ver sección 0).

## 13. Protocolo de commits y flujo de ramas

### A quién avisar y cuándo

- Al crear repo/estructura de ramas → avisar a Líder Técnico
- Antes de mergear cualquier `feature/*` a `develop` → PR con review del Líder Técnico
- Al cambiar/agregar un endpoint o contrato de API → avisar a Frontend (Jose López) **antes** de mergear
- Cuando una funcionalidad quede estable en `develop` → avisar a QA (Sebastián)
- Antes de release a `main` → ok de Líder Técnico + QA

### Una rama por TAREA, no por proyecto ni por commit

Una rama `feature/*` cubre una unidad de trabajo revisable en una sentada.
Ejemplos para Sprint 1: `feature/auth-sanctum-login`, `feature/crud-categorias`,
`feature/crud-equipos`, `feature/crud-unidades`.

### Ciclo de trabajo

```
git switch develop && git pull          # partir de lo último del equipo
git switch -c feature/crud-equipos      # rama para ESA tarea
# ...commits...
git push -u origin feature/crud-equipos # -u solo la primera vez
# abrir PR hacia develop en GitHub -> QA aprueba -> merge
# borrar la rama y empezar la siguiente desde develop actualizada
```

**Jose no delega los `git push`: los ejecuta él mismo.**

## 14. Pendientes activos (bloquean o casi bloquean el avance)

### Estado del repositorio (actualizado 2026-09-09)

- `feature/entorno-docker-y-api-base` (PR #1) y `feature/auth-sanctum-login`
  (PR #4, HU-01) **fusionadas a `develop`**.
- `feature/crud-usuarios-roles` (HU-02): **PR #5 aprobado y fusionado a
  `develop`** (2026-09-09). Se resolvieron 2 comentarios de review de Daniel
  antes del merge: paginación sin `max(1, ...)` (`per_page` negativo/0
  rompía) y el ejemplo de `docs/api/autenticacion.md` desactualizado tras
  agregar `is_active` a `UserResource`.
- `feature/crud-equipos` (HU-03): rebasada sobre `develop` ya con HU-02
  adentro, subida a origin, **PR abierto**. De paso se aprovechó para
  arreglar un problema de estilo suelto (`docker/esperar-bd.php`) que venía
  arrastrándose sin PR propio desde HU-01.
- Trabajo actual en **`feature/actualizar-estado-equipos`** (HU-04),
  ramificada desde `feature/crud-equipos` (no desde `develop`) porque
  depende del modelo `Equipo` de esa rama. Cuando el PR de HU-03 se apruebe
  y mergee, esta rama se rebasa sobre `develop`.
- **Backend de autenticación (HU-01) implementado y probado** — ver sección 16.
- **CRUD de usuarios y roles (HU-02) implementado y probado** — ver sección 17.
- **Registro de equipos (HU-03) implementado y probado** — ver sección 18.
- **Actualizar estado de equipos + historial (HU-04) implementado y
  probado** — ver sección 19.

### Tareas

- [ ] **Aclarar con el equipo quién lidera DevOps de acá en adelante** (Victor
      es el rol oficial; yo apoyo) — no bloquea Sprint 1, pero afecta CI/CD y Railway
- [ ] **Confirmar duración real del sprint** (1 semana vs 2 semanas)
- [ ] **Pedir al equipo que corrija la contradicción** en la sección "Por Qué"
      de arquitectura de su documento
- [ ] **Redefinir el modelo de datos con el equipo** — reconciliar la
      propuesta de 12 tablas con las 3 tablas mínimas del documento oficial.
      No tocar migraciones de negocio hasta acordar versión final
- [ ] Validación de matriz de permisos con UX/UI y PO (depende del punto anterior)
- [ ] Respuestas del PO a las 5 reglas de negocio (sección 6) → bloquea Sprint 2 (flujo de préstamos)
- [x] Backend de HU-01 (login/logout/me + roles vía Sanctum) implementado,
      con tests, PR abierto hacia `develop`, pendiente de review
- [x] **Restricción de funcionalidades por rol ya se puede probar
      end-to-end**: `/api/usuarios/*` (HU-02) es el primer grupo de rutas
      protegido con el middleware `role:admin` de Spatie. Un usuario
      autenticado sin rol `admin` recibe `403`.
- [ ] Validar que HU-01 a HU-05 (ya redactadas) coincidan con la implementación planeada de Sprint 1
- [x] **Estructura de usuarios y roles** — `RoleSeeder` crea los 3 roles
      oficiales (`admin`, `encargado`, `usuario`, ya cerrados en sección 3),
      idempotente (`firstOrCreate`). El usuario de prueba del seeder queda
      con rol `admin`.
- [x] **HU-02 (gestionar usuarios y roles) implementada** — ver sección 17
      para el detalle. El rol ya no es "por defecto": el admin lo elige
      explícitamente al crear cada usuario (`role` es un campo obligatorio
      de `POST /api/usuarios`), que era la decisión pendiente que se dejó
      abierta al cerrar HU-01.
- [x] **Modelo de datos cerrado por el PO (2026-09-05)** — ver sección 4.
      Ya no bloquea generar migraciones de negocio para las tablas incluidas
      (`categorias`, `equipos`, y luego `renovaciones`/`evidencias`/
      `historial_estados`/`prestamos` cuando lleguen sus HU)
- [x] **HU-03 (registrar equipos) implementada** — ver sección 18

## 15. Cómo debe comportarse una IA trabajando en este repo

- **Antes de generar migraciones/modelos/seeders de negocio, verificar que
  la tabla esté "Incluir" en la decisión de modelo de datos de la sección 4**
  (cerrada el 2026-09-05) — ya no hay que pedir confirmación para
  `categorias`/`equipos`/`renovaciones`/`evidencias`/`historial_estados`/
  `prestamos`, pero `mantenimientos`, `lista_espera` y `parametros_sistema`
  siguen sin decisión firme, no asumirlas
- No asumir resueltas las 5 reglas de negocio de la sección 6 — si hace falta
  un valor de esos, preguntar o dejarlo parametrizado en `parametros_sistema`
- No modificar decisiones de la sección 3 sin que el usuario lo pida explícitamente
- **Sí se puede avanzar con HU-01 (login/auth)** apoyándose en `users` +
  Spatie roles, que ya están cerrados y no dependen del debate del modelo de
  datos de negocio
- Mantener el formato de error de API consistente en cualquier endpoint nuevo
- **Nunca citar `CONTEXTO-PROYECTO.md` desde código, comentarios o docs que sí
  se versionan** (controllers, seeders, `docs/*.md`, README) — está en
  `.gitignore`, el resto del equipo no lo tiene. Si algo de acá es relevante
  para el equipo, va a `docs/decisiones.md` (o el doc que corresponda) en vez
  de citarse desde aquí. Esto ya causó un bloqueante real en code review
  (PR de HU-01, sección 14 tiene el detalle si hace falta contexto)
- Preferir explicaciones con el porqué de cada decisión, no solo código listo para pegar
- Si una tarea requiere generar código para pegar en otro agente (Claude Code, etc.), entregar el prompt completo y autocontenido

## 16. Cómo funciona la autenticación (HU-01, implementado en `feature/auth-sanctum-login`)

Sanctum en **modo API tokens (Bearer)**, no modo SPA/cookies — coherente con
que el frontend Blade consume la API server-to-server.

### Flujo

1. **`POST /api/login`** (`AuthController@login`, sin auth previa)
   - Valida `email` + `password` (`LoginRequest`); `device_name` es opcional
     (identifica el token, ej. "web"/"android", para poder revocarlo después
     sin afectar otros dispositivos).
   - Busca el usuario por email y compara el hash con `Hash::check`.
   - Si falla cualquiera de los dos, lanza el **mismo mensaje de error**
     tanto para "no existe el correo" como para "contraseña incorrecta" —
     así no se puede usar el login para enumerar qué correos están
     registrados.
   - Si es válido, crea un token con `$user->createToken(...)` y devuelve
     `{ "token": "...", "user": { id, name, email, roles } }`. `roles` sale
     de `getRoleNames()` (Spatie) — es lo que el frontend usa para restringir
     qué mostrar según el rol.
   - Middleware `throttle:6,1`: máximo 6 intentos por minuto por IP, para
     frenar fuerza bruta (cumple la regla de rate limiting de la sección 9).

2. **Rutas protegidas** (`Route::middleware('auth:sanctum')`):
   - **`GET /api/me`**: devuelve el usuario autenticado (mismo payload que
     login). El frontend lo usa para restaurar sesión al recargar la página.
   - **`POST /api/logout`**: borra únicamente
     `$request->user()->currentAccessToken()` — cerrar sesión en un
     dispositivo no revoca los tokens de los demás.
   - Sin token válido, cualquiera de las dos devuelve `401` con
     `{ "message": "..." }` en JSON siempre (se ajustó explícitamente el
     manejo de `AuthenticationException` para que no intente redirigir a una
     ruta `login` que no existe cuando la petición no pide JSON explícitamente).

### Usuario "activo" (regla de negocio de la HU-01 oficial)

La redacción formal de HU-01 agrega una regla de negocio que no estaba
implementada: **"Solo usuarios registrados y activos pueden ingresar."**
Se agregó `users.is_active` (boolean, default `true`, migración
`2026_09_03_031949_add_is_active_to_users_table`):

- `login()` valida email+password primero (igual que antes); si coinciden
  pero `is_active` es `false`, rechaza con `422` y un mensaje **distinto**
  ("Tu cuenta esta desactivada..."). Es intencional que sea distinto del
  mensaje genérico de credenciales inválidas: en ese punto el usuario ya
  demostró conocer la contraseña correcta, así que no hay nada nuevo que
  enumerar exponiendo que la cuenta existe pero está desactivada.
- Factory: nuevo estado `User::factory()->inactive()`.
- Por ahora no hay ningún endpoint para que un admin cambie `is_active` —
  eso es tarea de HU-02 (gestión de usuarios y roles). El campo existe y el
  login ya lo respeta, pero activar/desactivar usuarios manualmente hoy
  requeriría tocar la base de datos a mano o un `tinker`.

### Qué queda cubierto por tests (`tests/Feature/Api/AuthTest.php`)

Login con credenciales válidas/incorrectas/correo inexistente (mismo error),
usuario desactivado (mensaje distinto), que nunca se expone el hash de la
contraseña, que los roles viajan en la respuesta, validación de campos
obligatorios, el bloqueo por throttle al séptimo intento, `/me` y `/logout`
con y sin token, y que revocar un token no afecta a los demás dispositivos
del mismo usuario. 18/18 tests pasan (corridos con PHP local + sqlite en
memoria, `php artisan test`, sin necesitar Docker levantado).

### Qué falta para dar HU-01 por *completamente* cerrada

- El backend de login/logout/roles/usuario-activo ya está, y ya se puede
  probar la restricción por rol de punta a punta gracias a HU-02 (ver
  sección 17). Falta que el PR (ya abierto) se apruebe y que Frontend
  confirme que el contrato le sirve tal cual.

## 17. HU-02 — Gestión de usuarios y roles (`feature/crud-usuarios-roles`)

Como administrador, gestionar usuarios y asignarles rol. Documentación
completa del contrato en `docs/api/usuarios.md`.

### Piezas nuevas

- **Middleware `role:admin`**: Spatie no registra el alias en el estilo de
  `bootstrap/app.php` de Laravel 11+/13; se dio de alta a mano
  (`bootstrap/app.php`). Todo el grupo `/api/usuarios/*` lo usa.
- **`UserResource`** (`app/Http/Resources/UserResource.php`): shape único de
  usuario (`id`, `name`, `email`, `is_active`, `roles`) reutilizado por
  `AuthController` (login/me) y `UserController`, para que no se
  desincronicen entre sí.
- **`UserController`**: `index`, `store`, `show`, `update` (vía
  `Route::apiResource(...)->except('destroy')`) + `activar`/`desactivar`
  como rutas propias.
  - `store` exige `role` (uno de `admin`/`encargado`/`usuario`) y lo asigna
    con `assignRole()`.
  - `update` acepta `name`/`email`/`password`/`role` todos opcionales
    (`sometimes`); si llega `role`, usa `syncRoles()` — **reemplaza** el rol
    anterior, no lo acumula (cada usuario tiene uno solo a la vez).
  - `password` en `store`/`update` se pasa en texto plano al modelo: el cast
    `'password' => 'hashed'` de `User` ya lo hashea solo, no hace falta
    `Hash::make` manual (y hacerlo manual lo hashearía dos veces si no fuera
    porque `Hash::isHashed()` lo detecta).
  - `desactivar` bloquea que un admin se desactive a sí mismo (`422`) — sin
    este freno quedaría sin forma de revertirlo.
  - `index` está paginado (`?per_page=`, default 15, tope 100) — con
    `meta.current_page/last_page/per_page/total`.
- **No hay `destroy`/eliminar usuarios por ahora** — la HU-02 no lo pide
  (solo consultar, registrar, modificar, activar/desactivar, asignar rol);
  desactivar cumple el mismo propósito sin perder el historial. Se había
  implementado un `destroy` con soft delete, pero se revirtió a pedido del
  usuario para no salirse de los criterios de la HU tal como están
  redactados. Si más adelante se pide eliminar de verdad, soft delete
  (`deleted_at`) sigue siendo la opción recomendada para no dejar huérfano
  el historial que un usuario pueda tener (préstamos, etc.).

### Bug encontrado y corregido durante la implementación

Al crear un usuario sin pasar `is_active` explícitamente, el modelo en
memoria quedaba con `is_active = null` en la respuesta del `POST`, aunque en
la base de datos sí se aplicaba el default `true` de la columna — porque los
defaults de columna no se reflejan en la instancia de Eloquent recién creada
sin volver a consultarla. Se corrigió pasando `'is_active' => true`
explícito en `UserController::store`.

### Tests (`tests/Feature/Api/UserControllerTest.php`)

Autorización (403 sin rol admin, 401 sin token), listar, consultar,
registrar (con rol, rol inválido, correo duplicado), modificar
nombre/rol (confirma que `syncRoles` reemplaza y no acumula),
activar/desactivar (incluido que un admin no puede desactivarse a sí mismo),
y paginación (`per_page`, `meta`). 32/32 tests del proyecto pasan.

## 18. HU-03 — Registrar equipos (`feature/crud-equipos`)

Como administrador, registrar equipos para el catálogo. Documentación
completa del contrato en `docs/api/equipos.md`; el modelo de datos que la
habilita está en la sección 4 (decisión del PO, 2026-09-05).

### Piezas nuevas

- **Tabla `categorias`**: solo `id`/`nombre` (único). Sin CRUD propio
  todavía — se siembra con `CategoriaSeeder` (`Portátil`, `Tablet`,
  `De mesa`), llamado desde `DatabaseSeeder`, mismo patrón que `RoleSeeder`
  (evita quedar bloqueado por no tener a qué apuntar `categoria_id`).
- **Tabla `equipos`**: `codigo` (único), `nombre`, `categoria_id` (FK,
  `restrictOnDelete` — no se puede borrar una categoría en uso),
  `descripcion` (nullable), `estado`, `observaciones` (nullable).
  - `estado` **sin default de columna a propósito** (misma lección de
    `is_active` en HU-01/02: un default de columna no se refleja en el
    modelo recién creado en memoria) — lo asigna `EquipoController::store`
    explícitamente con `Equipo::ESTADO_INICIAL` (`'disponible'`).
    `StoreEquipoRequest` ni siquiera valida `'estado'` como campo de
    entrada: cualquier valor que mande el cliente se ignora.
  - 3 estados fijos en `Equipo::ESTADOS`: `disponible`, `en_prestamo`,
    `mantenimiento`. Las transiciones entre ellos son de HU-04, no de esta.
- **`EquipoController`**: `index` (paginado, mismo patrón que
  `UserController`), `store`, `show`. Sin `update`/`destroy` — no los pide
  esta HU (llegan con HU-04 y HU-05 respectivamente, si acaso).
- **Permisos por verbo, no por controller completo** (distinto de
  `UserController`, que es 100% `role:admin`): `GET /api/equipos` y
  `GET /api/equipos/{equipo}` son para **cualquier autenticado** (decisión
  explícita del usuario — HU-05 "consultar equipos disponibles" los va a
  necesitar así), mientras que `POST /api/equipos` sigue exclusivo de
  `admin`. Por eso son dos grupos de rutas separados en `routes/api.php` en
  vez de un solo `Route::apiResource(...)`.
- **`EquipoResource`**: incluye `categoria` como objeto anidado
  (`{id, nombre}`), no solo `categoria_id` — controller siempre hace
  `->load('categoria')`/`with('categoria')` para evitar N+1.

### Tests (`tests/Feature/Api/EquipoControllerTest.php` + `CategoriaSeederTest.php`)

Autorización (401 sin token, 403 sin rol admin al registrar, cualquier rol
puede listar), registrar (con categoría válida/inexistente, código
duplicado, campos obligatorios, y que el `estado` enviado por el cliente se
ignora), que un equipo registrado aparece en el listado, consultar un
equipo puntual, y que el seeder de categorías es idempotente.

## 19. HU-04 — Actualizar estado de equipos (`feature/actualizar-estado-equipos`)

Como administrador, cambiar el `estado` de un equipo, con historial. Rama
ramificada desde `feature/crud-equipos` (necesita el modelo `Equipo`).

### Piezas nuevas

- **4º estado**: `dado_de_baja`, agregado a `Equipo::ESTADOS`. Es
  **terminal** (`Equipo::ESTADO_TERMINAL`): `EquipoController::actualizarEstado`
  rechaza con `422` cualquier intento de cambiar el estado de un equipo que
  ya está `dado_de_baja`. Así se cumple literalmente "un equipo dado de baja
  no debe poder ser prestado" — nunca puede volver a `en_prestamo`.
- **`historial_estados`**: `equipo_id`, `estado_anterior` (nullable, null en
  el registro inicial), `estado_nuevo`, `user_id` (nullable — un equipo
  puede crearse por seeder/factory sin admin autenticado detrás),
  timestamps.
- **`App\Observers\EquipoObserver`**, no lógica en el controller: escucha
  `created` (registra `null -> estado_inicial`) y `updated` (solo si
  `wasChanged('estado')`, registra `anterior -> nuevo`). Se registra en
  `AppServiceProvider::boot()` (`Equipo::observe(...)`). Ventaja sobre
  loguear a mano en cada controller: cualquier código futuro que cambie
  `estado` (incluido lo que traiga HU-05/HU-06) queda cubierto sin
  acordarse de nada extra.
- **`Equipo::historialEstados()`**: `hasMany(HistorialEstado::class)`. Usa
  `->latest('id')`, **no** `->latest()` (que ordena por `created_at`) —
  varios cambios de estado en el mismo segundo (normal en tests, y posible
  en uso real) empatan en `created_at` y el orden queda indeterminado; `id`
  sí es monótono. Esto se detectó como bug real durante el desarrollo (un
  test fallaba de forma intermitente-determinista según el orden de
  ejecución).
- **`PATCH /api/equipos/{equipo}/estado`** (solo `admin`): único campo
  aceptado es `estado`, validado contra `Equipo::ESTADOS`. No es una edición
  general del equipo — HU-04 solo pide cambiar el estado, no otros campos.
- **`GET /api/equipos/{equipo}/historial`** (cualquier autenticado, mismo
  criterio que el resto de consultas de equipos): no lo pedía la HU
  explícitamente, pero un historial que nadie puede ver no cumple bien el
  espíritu de "debe quedar registrado". Devuelve más reciente primero.

### Qué NO se implementó (a propósito)

- **Ningún filtro de disponibilidad** en `GET /api/equipos` basado en
  `estado` — eso es HU-05 ("consultar equipos disponibles"), no esta.
- **Ninguna restricción de transición más allá de la terminal**: se puede
  pasar libremente entre `disponible`/`en_prestamo`/`mantenimiento` en
  cualquier orden. No hay todavía un sistema de préstamos real que dirija
  esas transiciones (HU-06+), así que no tiene sentido inventarle reglas
  que el equipo no ha pedido.

### Tests (`tests/Feature/Api/EquipoControllerTest.php`)

Cambiar estado (éxito, 403 sin admin, 422 con valor inválido), que
`dado_de_baja` bloquea futuros cambios, que registrar un equipo crea la
entrada inicial del historial, que el historial se puede consultar y viene
ordenado correctamente, y que un equipo creado sin admin autenticado
(factory/seeder) queda con `user_id = null` en su historial.
53/53 tests del proyecto pasan.

## 20. HU-05 — Catálogo de equipos disponibles (`feature/catalogo-equipos`)

Implementación de la consulta del catálogo con disponibilidad, visibilidad por roles, filtros combinados y ordenamiento. Contrato completo en `docs/api/HU-05-catalogo-equipos.md`.

### Decisiones técnicas y de negocio clave
- **Disponibilidad:** `equipos.estado = disponible`. El método de instancia `Equipo::isDisponible()` y el query scope `Equipo::disponible()` (`scopeDisponible`) son la **única fuente de verdad** de la regla de disponibilidad:
  - **Uso en HU-06:** Al crear una solicitud de préstamo sobre un equipo puntual, la HU-06 debe invocar obligatoriamente `$equipo->isDisponible()`; si devuelve `false`, la solicitud debe ser rechazada. Si la HU-06 lista o consulta equipos elegibles para préstamo, debe aplicar el scope `Equipo::disponible()`.
- **Visibilidad segura por defecto (`dado_de_baja`) y roles administrativos:** Los equipos dados de baja solo son visibles para `admin` y `encargado`. Para el rol `usuario` (solicitante) y cualquier usuario sin roles administrativos, quedan excluidos del listado general mediante `scopeVisiblesEnCatalogo()`, y su detalle individual devuelve `404 Recurso no encontrado.`, exactamente igual que un ID inexistente. El criterio de autorización está centralizado en el modelo `User` mediante el método de instancia `User::esPersonalAdministrativo()`, que evalúa `$this->hasAnyRole(['admin', 'encargado'])`. Este es el criterio transversal a reutilizar en otras HU que requieran distinguir personal administrativo.
- **Campo `observaciones` interno:** `observaciones` es un campo interno, solo visible para `admin` y `encargado`. En `EquipoResource`, se expone condicionalmente con `$this->when($request->user()?->esPersonalAdministrativo() ?? false, $this->observaciones)`. Para el rol `usuario` y usuarios sin rol, el campo no existe en la respuesta JSON (no aparece ni como `null`). La información que el solicitante deba conocer sobre el equipo va en `descripcion`.
- **Constante `ESTADO_DADO_DE_BAJA`:** Se definió como `'dado_de_baja'` en `Equipo` de esta rama y queda pendiente de unificar con la rama de HU-04 una vez que se haga el merge a `develop`.
- **Idioma y localización:** Toda la API responde en español neutro (`APP_LOCALE=es`, `APP_FALLBACK_LOCALE=en` en `.env.example` y `phpunit.xml`), con traducciones mantenidas a mano en `lang/es/` sin dependencias externas, mensajes de error HTTP en `lang/es/errores.php` y test de paridad de claves en `tests/Unit/TraduccionesTest.php`.
- **Formato estándar de errores HTTP:** Estandarizado en `bootstrap/app.php` con `{ "message": "..." }` para 401, 403, 404, 405, 429, otros 4xx y 500 (con `app.debug=false`). Documentado en `docs/api/errores.md`.
- **Paginación tolerante:** El parámetro `per_page` se normaliza en el controlador entre 1 y 100 con `max(1, min($val, 100))`, sin arrojar 422 ante valores inválidos o fuera de rango, consistente con `UserController`.
- **Parámetros vacíos:** Parámetros vacíos en la query string (`categoria_id=`, `disponible=`) se ignoran limpiamente para soportar selectores con opción "Todos" en frontend.
- **Búsqueda textual:** `buscar` (`scopeBuscar`) escapa `%` y `_` de manera compatible con SQLite y MySQL mediante `ESCAPE '!'`, permitiendo búsquedas literales de comodines.

### Pendientes de negocio
- [ ] Unificar `ESTADO_DADO_DE_BAJA` con la constante de la HU-04 tras su merge a `develop`.

## 21. HU-08 — Gestión de solicitudes de préstamo (`feature/aprobacion-prestamos`)

Como personal de préstamo (`admin`, `encargado`), gestionar una solicitud (aprobación o rechazo con motivo obligatorio) para controlar qué préstamos pueden realizarse. Documentación completa del contrato en `docs/api/prestamos.md`.

### Alcance implementado
- **Aprobación (`POST /api/prestamos/{prestamo}/aprobacion` — KAN-98):**
  - Exclusivo para roles `admin` y `encargado` (rol `usuario` recibe `403`).
  - Solo permite transicionar préstamos en estado `solicitado` hacia `aprobado` (`TransicionPrestamoService`).
  - Valida disponibilidad física del equipo (`Equipo::isDisponible()`). Si está en mantenimiento, en préstamo o dado de baja, rechaza con `422` descriptivo en `errors.equipo`.
  - Orden estricto de bloqueo pesimista en base de datos (`lockForUpdate()`): `equipo -> préstamo` para prevenir interbloqueos y condiciones de carrera.
  - La aprobación no altera el estado físico del equipo (se mantiene en `disponible` hasta la entrega física en HU-09).
  - Registra `gestionado_por = auth()->id()`, `fecha_aprobacion = now()`.

- **Rechazo (`POST /api/prestamos/{prestamo}/rechazo` — KAN-99):**
  - Exclusivo para roles `admin` y `encargado` (rol `usuario` recibe `403`).
  - Solo permite transicionar préstamos en estado `solicitado` hacia `rechazado`.
  - Motivo de rechazo obligatorio: validado con la regla reutilizable `App\Rules\TextoNoVacio` (máx. 1000 caracteres, no vacío ni únicamente espacios en blanco).
  - Almacena el motivo recortado (`trim`) en `motivo_rechazo`, junto con `fecha_rechazo = now()` y `gestionado_por = auth()->id()`.
  - No altera el estado del equipo ni requiere bloqueo sobre `equipos`. La solicitud rechazada no bloquea nuevas solicitudes sobre el equipo ni computa dentro del límite de préstamos activos del solicitante (`max_activos_por_usuario`).

- **Consulta y filtro por estado (`GET /api/prestamos?estado=` — KAN-97):**
  - Soporta parámetro opcional `estado` validado contra `EstadoPrestamo`. Valores inválidos devuelven `422` con `errors.estado`.
  - Respeta visibilidad según rol: `usuario` común solo ve sus propios préstamos; personal administrativo ve todos los préstamos.
  - El detalle puntual (`GET /api/prestamos/{id}`) expone `usuario_gestion` reducido (`{ id, name }`).
  - `motivo_rechazo` es visible tanto para personal administrativo como para el solicitante dueño del préstamo.
  - Paridad estricta de campos en el recurso `PrestamoResource`: lista canónica `Prestamo::RELACIONES_RECURSO` precargada en todos los endpoints para garantizar que todas las respuestas del recurso devuelvan las mismas claves según el rol, evitando problemas de N+1.


