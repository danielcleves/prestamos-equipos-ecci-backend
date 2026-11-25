# Despliegue y CI/CD — GitHub Actions + Render + Aiven

Guía del pipeline de integración y despliegue continuo del proyecto. Aplica a los dos
repos (`backend` y `frontend`); el frontend enlaza a este documento.

Todo corre en planes gratuitos.

## Ambientes

| Ambiente | Rama | Frontend | Backend (API) | Base de datos |
|---|---|---|---|---|
| Desarrollo / QA | `develop` | <https://prestamos-ecci-frontend-dev.onrender.com> | <https://prestamos-ecci-backend-dev.onrender.com/api/ping> | Aiven MySQL `prestamos-ecci-db` |
| Producción | `main` | pendiente | pendiente | pendiente |

Las credenciales (usuario admin, contraseña de Aiven, deploy hooks) **no** se guardan en
el repo: se comparten por privado.

## Cómo funciona el pipeline

El pipeline es `.github/workflows/ci-cd.yml` (uno por repo) y tiene dos jobs:

```
PR hacia develop/main ──► Pruebas ──► ✔/✘ en el PR (no despliega)

push / merge a develop ──► Pruebas ──(pasan)──► Desplegar ──► deploy hook ──► Render (servicio -dev)
push / merge a main    ──► Pruebas ──(pasan)──► Desplegar ──► deploy hook ──► Render (servicio de producción)
                                   └─(fallan)──► no se despliega nada
```

**Job "Pruebas (PHPUnit)"**

- Instala PHP 8.3 y dependencias (con caché de Composer), compila los assets con Vite y
  prepara `.env` + `APP_KEY`.
- Backend: corre la suite dos veces, SQLite en memoria (`phpunit.xml`) y MySQL 8 en un
  contenedor de servicio (`phpunit.mysql.xml`), porque producción usa MySQL.
- Frontend: corre la suite con SQLite en memoria; las llamadas al backend se simulan con
  `Http::fake()`, así que no necesita la API levantada.

**Job "Desplegar en Render"**

- Corre solo en push a `develop` o `main` y solo si "Pruebas" pasó (`needs: pruebas`).
- Llama al deploy hook de Render de esa rama con `ref=<sha>`, para desplegar exactamente
  el commit que pasó las pruebas.
- Si el secreto del hook no existe, termina en verde con el aviso "se omite el despliegue".

**Reglas**

- Da igual si el cambio llega por PR o por push directo: cualquier push a `develop` o
  `main` dispara el pipeline.
- Render tiene el **auto-deploy desactivado**: solo despliega cuando lo llama el pipeline,
  así nunca se publica código sin probar.
- Los cambios que solo tocan documentación (`**.md`, `docs/**`) no disparan el pipeline.
- Las actions están fijadas por SHA de commit y el workflow solo tiene permiso
  `contents: read`.
- Al arrancar, el contenedor del backend espera a la base de datos y corre las
  migraciones (`docker/entrypoint.sh`): no hay que migrar a mano en cada despliegue.

## Flujo para el equipo

1. Crear `feature/*` o `fix/*` desde `develop` y abrir un PR hacia `develop`.
2. Esperar el check **Pruebas (PHPUnit)** en verde. Si sale en rojo, abrir **Details**
   para ver qué prueba falló.
3. El Líder Técnico revisa y fusiona.
4. El pipeline prueba de nuevo y despliega: en 2-3 minutos el cambio está en el ambiente
   de `develop`, listo para que QA lo valide.

Dónde mirar:

| Qué | Dónde |
|---|---|
| Resultado de las pruebas y del despliegue | GitHub → pestaña **Actions** del repo |
| Historial de despliegues por ambiente | GitHub → **Environments** (`develop` / `produccion`) |
| Commit en vivo y deploys en Render | Render → servicio → **Events** (los automáticos dicen *Deploy hook*) |
| Errores de la aplicación | Render → servicio → **Logs** (buscar `ERROR`) |

Una funcionalidad sin pruebas puede pasar el pipeline con errores: el pipeline solo
detecta lo que las pruebas cubren. Cada funcionalidad nueva debería llegar con su prueba.

## Montaje desde cero

Pasos para crear un ambiente (así se montó `develop`; `main` se monta igual).

### 1. Base de datos en Aiven

1. En <https://console.aiven.io>: **Create service → MySQL → tier Free**
   (`Free-1-1gb`, región North America). Revisar que el costo diga **$0**: el plan
   `developer-1` que aparece por defecto usa créditos de prueba y se apaga al acabarse.
2. En **Overview → Connection information** anotar host, puerto, usuario (`avnadmin`),
   contraseña y base (`defaultdb`).
3. Descargar el **CA certificate** (`ca.pem`): Aiven exige SSL.

### 2. Servicios en Render

**New → Web Service → Public Git Repository** con la URL del repo (son públicos, no
hace falta conectar la cuenta de GitHub). Render detecta "Node" por el `package.json`:
hay que cambiarlo a **Docker**.

| Ajuste | Backend | Frontend |
|---|---|---|
| Name | `prestamos-ecci-backend-dev` | `prestamos-ecci-frontend-dev` |
| Language | Docker | Docker |
| Branch | `develop` | `develop` |
| Region | Oregon (US West) | Oregon (US West) |
| Dockerfile Path | `Dockerfile` | `Dockerfile` |
| Compute | **Free** ($0) | **Free** ($0) |
| Health Check Path | `/api/ping` | `/up` |
| **Auto-Deploy** | **Off** | **Off** |

Las variables se pueden pegar todas juntas con **Add from .env**.

#### Variables de entorno — backend

| Variable | Valor |
|---|---|
| `APP_KEY` | salida de `php artisan key:generate --show` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://<backend>.onrender.com` |
| `APP_LOCALE` | `es` |
| `APP_FALLBACK_LOCALE` | `en` |
| `PORT` | `8000` (el `Dockerfile` del backend escucha en ese puerto) |
| `LOG_CHANNEL` | `stderr` |
| `DB_CONNECTION` | `mysql` |
| `DB_HOST` / `DB_PORT` | los de Aiven |
| `DB_DATABASE` | `defaultdb` |
| `DB_USERNAME` / `DB_PASSWORD` | los de Aiven |
| `MYSQL_ATTR_SSL_CA` | `/etc/secrets/ca.pem` |

En **Advanced → Secret Files** agregar `ca.pem` con el contenido completo del
certificado (Render lo monta en `/etc/secrets/ca.pem`).

> Cuando `APP_KEY` llega como variable de entorno, el contenedor no crea `.env`:
> cualquier variable que **no** se defina en Render toma el valor por defecto de
> `config/` (por ejemplo `APP_ENV=production` y `APP_LOCALE=en`), así que hay que
> definir todas las de la tabla.

#### Variables de entorno — frontend

| Variable | Valor |
|---|---|
| `APP_KEY` | otra clave, distinta a la del backend |
| `APP_URL` | `https://<frontend>.onrender.com` |
| `BACKEND_API_URL` | `https://<backend>.onrender.com/api` |

El resto (`APP_ENV=production`, `APP_DEBUG=false`, sesión y caché en archivos) ya viene
por defecto en el `Dockerfile` del frontend. El puerto lo asigna Render con `PORT`.

### 3. Deploy hooks como secretos de GitHub

En cada servicio de Render: **Settings → Deploy Hook → copiar URL**.
En cada repo de GitHub: **Settings → Secrets and variables → Actions → New repository secret**:

| Secreto | Valor |
|---|---|
| `RENDER_DEPLOY_HOOK_DEVELOP` | deploy hook del servicio de `develop` |
| `RENDER_DEPLOY_HOOK_MAIN` | deploy hook del servicio de `main` |

> Requiere permiso de **admin** del repo. Como los repos son personales de
> `danielcleves`, solo el dueño puede crear secretos (a un colaborador GitHub solo
> le permite read o write). Quien configure Render le envía las URLs de los hooks
> **por privado** y él las carga. No publicar los hooks: con la URL cualquiera
> puede disparar un despliegue.

### 4. Datos iniciales y usuario admin

El plan gratuito de Render no tiene consola, así que los datos iniciales se cargan desde
una máquina local con la imagen del backend apuntando a Aiven.

Crear fuera del repo un archivo `aiven.env` (no versionarlo) y guardar junto a él el
`ca.pem`:

```
DB_CONNECTION=mysql
DB_HOST=<host de Aiven>
DB_PORT=<puerto de Aiven>
DB_DATABASE=defaultdb
DB_USERNAME=avnadmin
DB_PASSWORD=<contraseña de Aiven>
MYSQL_ATTR_SSL_CA=/ca.pem
```

Construir la imagen y correr migraciones y seeders (en Git Bash, desde la raíz del
backend; ajustar las rutas a donde estén `aiven.env` y `ca.pem`):

```bash
docker build -t prestamos-backend .
export MSYS_NO_PATHCONV=1
AIVEN="--env-file C:\ruta\aiven.env -v C:\ruta\ca.pem:/ca.pem"

docker run --rm $AIVEN --entrypoint php prestamos-backend artisan migrate --force
docker run --rm $AIVEN --entrypoint php prestamos-backend artisan db:seed --class=RoleSeeder --force
docker run --rm $AIVEN --entrypoint php prestamos-backend artisan db:seed --class=CategoriaSeeder --force
```

No usar `db:seed` sin `--class`: `DatabaseSeeder` crea un usuario de prueba.

Crear el usuario admin (la contraseña se pasa por variable de entorno para no dejarla
en el código):

```bash
docker run --rm $AIVEN -e ADMIN_PASSWORD='<contraseña>' --entrypoint php prestamos-backend \
  artisan tinker --execute '$u = App\Models\User::firstOrCreate(["email" => "admin@ecci.edu.co"], ["name" => "Administrador ECCI", "password" => getenv("ADMIN_PASSWORD"), "is_active" => true]); $u->syncRoles(["admin"]);'
```

### 5. Proteger las ramas

Igual que los secretos, requiere permiso de **admin**: lo configura el dueño del repo.

**Settings → Branches → Add rule** para `develop` y `main`:
"Require status checks to pass before merging" → marcar **Pruebas (PHPUnit)**.
Así un PR con pruebas rotas no se puede fusionar.

> El workflow ignora los cambios que solo tocan documentación (`**.md`, `docs/**`).
> Si **Pruebas (PHPUnit)** se marca como check obligatorio, un PR que solo cambie
> documentación queda esperando ese check y no se puede fusionar: en ese caso el
> dueño puede fusionarlo saltando la regla, o no marcar el check como obligatorio.

## Pendiente: ambiente de producción (`main`)

El pipeline ya soporta `main`; falta la infraestructura:

- [ ] Crear los servicios de Render de producción (pasos 1-2, con `Branch: main`).
- [ ] Decidir si producción usa su propia base en Aiven o comparte la de `develop`.
- [ ] Enviar los deploy hooks al dueño del repo para crear `RENDER_DEPLOY_HOOK_MAIN`.
- [ ] Cargar datos iniciales y usuario admin si la base es nueva (paso 4).
- [ ] Completar la tabla de **Ambientes**.

## Operación y solución de problemas

| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| La primera carga tarda ~1 minuto | El servicio free se duerme tras ~15 min sin tráfico | Esperar; antes de una presentación abrir `/api/ping` y el frontend unos minutos antes |
| **502** en el sitio | El servicio está despertando, o Render tiene un incidente | Esperar 1 minuto y recargar; revisar <https://status.render.com>. El 14/10/2026 un incidente de Render (2:32-5:18 p. m.) impidió encender servicios free |
| El backend no arranca y el log repite `Esperando a la base de datos... (n/60)` | Aiven apagado por inactividad (plan Free), o host/puerto/`ca.pem` mal configurados | En Aiven revisar que el servicio diga **Running** (si no, **Power on**) y luego **Manual Deploy** en Render |
| Error 500 en una pantalla | Error de la aplicación | Render → servicio → **Logs**, buscar `ERROR`: el mensaje trae archivo y línea |
| El job "Desplegar" sale en verde con "se omite el despliegue" | Falta el secreto del deploy hook de esa rama | Crear el secreto (paso 3) |
| Un merge no aparece en el sitio | La rama del servicio en Render no es la correcta, o el deploy falló | Render → **Settings → Branch** debe ser `develop`/`main`; revisar **Events** y, si hace falta, **Manual Deploy → Deploy latest commit** |

**Por qué el backend no crea `.env` en Render.** Si existe un `.env`, `php artisan serve`
no pasa a sus procesos hijos las variables que también están en el archivo. Cuando el
entrypoint copiaba `.env.example`, las migraciones (proceso principal) se conectaban a
Aiven, pero las peticiones leían `DB_HOST=prestamos_db` del `.env.example` y fallaban.
Por eso el entrypoint solo crea `.env` cuando `APP_KEY` no viene del entorno (Docker
local).

## Notas del plan gratuito

- **Render:** los servicios free se duermen tras ~15 minutos sin tráfico, no tienen
  consola ni one-off jobs, y los logs se conservan por tiempo limitado.
- **Aiven:** el plan Free (1 GB) se apaga solo tras un periodo de inactividad; los datos
  no se pierden, pero hay que encenderlo desde la consola.
- Revisar <https://status.render.com> y los avisos de mantenimiento antes de una
  presentación.
