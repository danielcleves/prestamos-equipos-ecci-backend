# Despliegue (CI/CD) — Render + Aiven + GitHub Actions

Todo en plan gratuito. Aplica a los dos repos (`backend` y `frontend`).

## Cómo funciona

```
PR a develop/main ──► GitHub Actions: pruebas PHPUnit ──► ✔/✘ en el PR (no despliega)

push / merge a develop ──► pruebas ──(pasan)──► deploy hook Render (servicio develop)
push / merge a main    ──► pruebas ──(pasan)──► deploy hook Render (servicio main)
                                  └─(fallan)──► no se despliega nada
```

- Da igual si el cambio llega por PR o por push directo: el workflow
  `.github/workflows/ci-cd.yml` se dispara con cualquier push a `develop` o `main`.
- Render tiene el **auto-deploy desactivado**; solo despliega cuando GitHub Actions
  llama al deploy hook, y le pasa `ref=<sha>` para desplegar exactamente el commit probado.
- El backend corre la suite dos veces: SQLite en memoria (`phpunit.xml`) y MySQL 8
  (`phpunit.mysql.xml`), porque producción usa MySQL.
- Las migraciones se ejecutan solas al arrancar el contenedor del backend
  (`docker/entrypoint.sh`).

## 1. Base de datos en Aiven (una vez)

1. Crear un servicio **MySQL** gratuito en <https://aiven.io>.
2. Anotar host, puerto, usuario (`avnadmin`), contraseña y base (`defaultdb`).
3. Descargar el **CA certificate** (`ca.pem`): Aiven exige SSL.

## 2. Servicios en Render (una vez)

Crear **New → Web Service → Docker** por cada repo. Para empezar basta con la rama
`develop`; cuando funcione, se crean los de `main` igual.

| Ajuste | Backend | Frontend |
|---|---|---|
| Branch | `develop` (luego otro servicio con `main`) | ídem |
| Runtime | Docker (usa el `Dockerfile` del repo) | Docker |
| Plan | Free | Free |
| **Auto-Deploy** | **Off** (Settings → Build & Deploy) | **Off** |
| Health check path | `/api/ping` | `/up` |

### Variables de entorno — backend

| Variable | Valor |
|---|---|
| `APP_KEY` | salida de `php artisan key:generate --show` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<backend>.onrender.com` |
| `APP_LOCALE` | `es` |
| `APP_FALLBACK_LOCALE` | `en` |
| `PORT` | `8000` |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` | los de Aiven |
| `DB_DATABASE` | `defaultdb` |
| `DB_USERNAME` / `DB_PASSWORD` | los de Aiven |
| `MYSQL_ATTR_SSL_CA` | `/etc/secrets/ca.pem` |

Y en **Secret Files** subir `ca.pem` (Render lo monta en `/etc/secrets/ca.pem`).

> Importante: cuando `APP_KEY` llega como variable de entorno, el contenedor no crea `.env`:
> cualquier variable que **no** se defina en Render toma el valor por defecto de `config/`
> (por ejemplo `APP_ENV=production` y `APP_LOCALE=en`), así que hay que definir todas las de la tabla.

### Variables de entorno — frontend

| Variable | Valor |
|---|---|
| `APP_KEY` | otra clave distinta a la del backend |
| `APP_URL` | `https://<frontend>.onrender.com` |
| `BACKEND_API_URL` | `https://<backend>.onrender.com/api` |

El resto (`APP_ENV=production`, `APP_DEBUG=false`, sesión en archivos, puerto 8000)
ya viene por defecto en el `Dockerfile` del frontend.

## 3. Deploy hooks como secretos de GitHub (una vez)

En cada servicio de Render: **Settings → Deploy Hook → copiar URL**.
En cada repo de GitHub: **Settings → Secrets and variables → Actions → New repository secret**:

| Secreto | Valor |
|---|---|
| `RENDER_DEPLOY_HOOK_DEVELOP` | deploy hook del servicio de `develop` |
| `RENDER_DEPLOY_HOOK_MAIN` | deploy hook del servicio de `main` |

Si un secreto no existe, el job de despliegue de esa rama termina en verde con un aviso
("se omite el despliegue"), así se puede activar primero `develop` y después `main`.

## 4. Datos iniciales (una vez)

El plan gratuito de Render no tiene consola, así que los roles y categorías se cargan
desde una máquina local apuntando a Aiven (con las variables de Aiven en el entorno):

```bash
php artisan db:seed --class=RoleSeeder --force
php artisan db:seed --class=CategoriaSeeder --force
```

No usar `db:seed` sin `--class`: `DatabaseSeeder` crea un usuario de prueba.

## 5. Proteger las ramas (recomendado)

**Settings → Branches → Add rule** para `develop` y `main`:
"Require status checks to pass before merging" → marcar **Pruebas (PHPUnit)**.
Así un PR con pruebas rotas no se puede fusionar.

## Notas del plan gratuito

- Los servicios free de Render se duermen tras ~15 min sin tráfico; la primera
  petición tarda ~1 minuto. Abrir el sitio unos minutos antes de una presentación.
- Ver el estado de cada corrida en la pestaña **Actions** del repo y el historial de
  despliegues en **Environments** (`develop` / `produccion`).
