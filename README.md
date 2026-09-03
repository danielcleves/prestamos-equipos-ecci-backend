# Préstamo de Equipos ECCI — Backend

[![Rama principal](https://img.shields.io/badge/branch-main-blue)](https://github.com/danielcleves/prestamos-equipos-ecci-backend/tree/main)
[![Laravel](https://img.shields.io/badge/Laravel-13-red)](https://laravel.com)
[![Licencia](https://img.shields.io/badge/license-MIT-green)](LICENSE)

Backend del sistema **Préstamo de Equipos** de la Universidad ECCI (Semestre 8 · Gestión de Software). Es el **repositorio de backend**, implementado como una **API REST** con Laravel.

> 🖥️ El frontend (interfaz de usuario) vive en su propio repositorio:
> [**prestamos-equipos-ecci-frontend**](https://github.com/danielcleves/prestamos-equipos-ecci-frontend)

## 📋 Descripción del proyecto

El sistema gestiona el **préstamo de equipos** de la universidad. Sus funcionalidades principales son:

- **Solicitar** un equipo.
- **Entregar** un equipo al solicitante.
- **Devolver** un equipo.
- **Registrar retrasos** en las devoluciones.

Este repositorio es el encargado de la **lógica de negocio y los datos** (API + base de datos). El frontend consume esta API; **no accede directamente a la base de datos**.

## 🧰 Stack tecnológico

- **Laravel 13** (framework PHP).
- **PHP 8.3+**.
- API REST (consumida por el frontend).
- **MySQL 8.0** como base de datos.
- **Docker + Docker Compose** para el entorno de desarrollo.

## ✅ Requisitos previos

- **Docker Desktop** (incluye Docker Compose).

Verifica que esté disponible:

```sh
docker --version
docker compose version
```

> 💡 No necesitas instalar PHP, Composer ni MySQL en tu equipo: todo corre dentro de contenedores.

## 🚀 Instalación y puesta en marcha

```sh
# 1. Clonar el repositorio
git clone git@github.com:danielcleves/prestamos-equipos-ecci-backend.git
cd prestamos-equipos-ecci-backend

# 2. Crear el archivo de entorno
cp .env.example .env

# 3. Levantar el entorno (backend + MySQL)
docker compose up -d --build
```

El backend queda disponible en `http://localhost:8000`.

El primer arranque tarda varios minutos, porque descarga las imágenes e instala las dependencias. Los siguientes son cuestión de segundos.

Al levantar, el contenedor se encarga automáticamente de:

1. Instalar las dependencias de Composer.
2. Generar la `APP_KEY` si no existe.
3. Esperar a que MySQL acepte conexiones.
4. Aplicar las migraciones pendientes.

No hace falta ejecutar `composer install`, `php artisan key:generate` ni `php artisan migrate` a mano.

### Puertos

| Servicio | En el host | Variable en `.env` |
|---|---|---|
| API (backend) | `8000` | `APP_PORT` |
| MySQL | `3307` (solo `127.0.0.1`) | `DB_PORT_HOST` |

MySQL se publica en el `3307` para no chocar con una instalación local en el `3306`. Si tienes alguno de esos puertos ocupado, cámbialo en tu `.env` **sin modificar `docker-compose.yml`**.

> ⚠️ Cada desarrollador levanta **su propia base de datos** local, con sus propios datos. No se comparte entre el equipo: para partir de datos comunes se usan los *seeders* del repositorio.

### Comandos útiles

```sh
docker compose logs -f prestamos_backend                  # ver logs
docker compose exec prestamos_backend php artisan test    # ejecutar pruebas
docker compose exec prestamos_backend php artisan <cmd>   # cualquier comando artisan
docker compose restart                                    # tras un git pull con migraciones nuevas
docker compose down                                       # bajar (conserva la base de datos)
```

## 🔌 Endpoints disponibles

| Método | Endpoint | Descripción | Respuesta |
|---|---|---|---|
| `GET` | `/api/ping` | Verifica que la API está operativa | `{"status": "ok"}` |
| `GET` | `/up` | Healthcheck nativo de Laravel | `200 OK` |

Para comprobar que el entorno quedó bien levantado:

```sh
curl http://localhost:8000/api/ping
# {"status":"ok"}
```

Es el chequeo más rápido para **QA y despliegue**: si `/api/ping` responde, la API está arriba y atendiendo peticiones.

## 🔐 Autenticación

La API usa **Laravel Sanctum en modo tokens (Bearer)**: `POST /api/login`,
`POST /api/logout` y `GET /api/me`.

📖 Documentación completa (reglas, ejemplos de respuesta, cómo probar con
curl/Postman): [`docs/api/autenticacion.md`](docs/api/autenticacion.md)

## 🌿 Estrategia de ramas

Flujo de trabajo del repositorio: `main` (producción) → `develop` (integración + QA) → `feature/*` (desarrollo de cada tarea).

```
main ───────► producción (solo DevOps)
   ▲
   │ merge cuando QA aprueba develop
develop ────► integración + QA principal
   ▲
   │ PR desde feature
feature/* ──► trabajo del desarrollador
```

### Ciclo de trabajo

1. El desarrollador crea `feature/*` desde `develop`, desarrolla su tarea y abre un **Pull Request hacia `develop`**.
2. El desarrollador solicita el PR hacia `develop`. El **Líder Técnico hace el code review técnico** (conflictos, buenas prácticas, tests) y lo aprueba para que entre a `develop`.
3. Una vez aprobado el code review, el PR se fusiona a `develop`. Es ahí donde **QA hace la validación funcional**, probando el código ya integrado junto a las demás features.
4. Cuando QA valida todo en `develop`, se hace el merge de `develop` → `main`.
5. **DevOps** despliega la aplicación desde `main`.

### Code review y validación funcional

Se distinguen dos momentos distintos dentro del flujo:

| Momento | Dónde | Quién / Qué |
|---|---|---|
| Al PR (code review) | revisa `feature/*` antes de entrar a `develop` | **Líder Técnico**: revisión técnica (conflictos, buenas prácticas, tests) |
| Tras el merge (validación funcional) | ya en `develop` con todo integrado | **QA**: prueba funcional de la aplicación y OK para pasar a `main` |

### Reglas de aprobación por nivel

Cada salto de nivel requiere la aprobación del rol correspondiente:

- `feature/* → develop`: aprueba el **Líder Técnico** (code review técnico) y **QA** valida funcionalmente lo integrado en `develop`.
- `develop → main`: aprueba **QA** (validación funcional) y lo ejecuta **DevOps**.

## 📁 Estructura del proyecto

```
app/                  Lógica de la aplicación (controllers, models, services)
routes/               Definición de rutas y endpoints de la API
database/             Migraciones, seeds y factories
docs/api/             Documentación detallada de la API (endpoints, ejemplos, cómo probar)
config/               Configuración del framework
public/               Punto de entrada pública (index.php)
tests/                Pruebas automáticas
docker/               Script de arranque del contenedor (entrypoint)
Dockerfile            Imagen del backend (PHP 8.3 + extensiones)
docker-compose.yml    Servicios del entorno: backend + MySQL
```

## 👥 Participantes

| Nombre | Rol | Perfil de GitHub |
|---|---|---|
| Emmanuel Valencia | Product Owner / Analista de Negocio / Gestor del Proyecto / Delivery Manager | [Emm2704](https://github.com/Emm2704) |
| Daniel Cleves | Líder Técnico | [danielcleves](https://github.com/danielcleves) |
| Alejandro Molina | UX/UI Designer | [AlejoMolina09](https://github.com/AlejoMolina09) |
| Jose López (Kota) | Desarrollo Frontend | [kotaErn650](https://github.com/kotaErn650) |
| José David | Desarrollo Backend | [JoseMedina-prog](https://github.com/JoseMedina-prog) |
| Sebastián Rodríguez | QA y Automatización de Pruebas | [SebasRCam](https://github.com/SebasRCam) |
| Victor Marín | DevOps, Despliegue y Observabilidad | [V53M](https://github.com/V53M) |

## 📄 Licencia

Proyecto académico basado en [Laravel](https://laravel.com), distribuido bajo la [licencia MIT](https://opensource.org/licenses/MIT).
