# Guía de consumo de la API

Cómo hablar con la API de préstamos de equipos ECCI: autenticarse, leer las
respuestas, entender los errores y encadenar el ciclo completo de un préstamo.

Esta guía es la **puerta de entrada**. Para el detalle campo por campo de cada
endpoint están las referencias por módulo:

| Documento | Cubre |
|---|---|
| [autenticacion.md](autenticacion.md) | login, logout, me |
| [equipos.md](equipos.md) | catálogo, historial, revisiones |
| [prestamos.md](prestamos.md) | ciclo completo del préstamo |
| [usuarios.md](usuarios.md) | gestión de usuarios |
| [errores.md](errores.md) | catálogo de errores |

---

## 1. Lo esencial en 30 segundos

Es una **API REST que solo habla JSON**. No sirve vistas ni HTML.

1. Pides un token con `POST /api/login`.
2. Mandas ese token en la cabecera `Authorization` en todo lo demás.
3. Las respuestas vienen envueltas en `data`; los listados añaden `meta`.

```bash
# 1. Token
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"password","device_name":"mi-app"}'

# 2. Cualquier otra petición
curl http://127.0.0.1:8000/api/equipos \
  -H "Authorization: Bearer 1|AbCdEf..." \
  -H "Accept: application/json"
```

---

## 2. URL base

```
http://127.0.0.1:8000/api
```

`/api` es un **prefijo, no un endpoint**: abrirlo en el navegador devuelve 404.
Siempre va seguido de una ruta (`/api/ping`, `/api/equipos`...).

El único endpoint que funciona sin token y desde el navegador es:

```
GET /api/ping   →   {"status":"ok"}
```

Úsalo para comprobar que el servidor está vivo.

---

## 3. Autenticación

### 3.1 Obtener el token

```http
POST /api/login
Content-Type: application/json
Accept: application/json

{
  "email": "test@example.com",
  "password": "password",
  "device_name": "web-frontend"
}
```

Respuesta `200`:

```json
{
  "token": "1|k7DQi02tEeugCsuiYjO5eUHa6GXRT5Hd5K8hJKu479db",
  "user": {
    "id": 1,
    "name": "Test User",
    "email": "test@example.com",
    "is_active": true,
    "roles": ["admin"]
  }
}
```

`device_name` es opcional (por defecto `"api"`) y sirve para identificar la
sesión; útil si más adelante quieres listar o revocar dispositivos.

**Guarda `user.roles`**: es lo que te dice qué puede ver y hacer esta persona,
y con ello decides qué pintar en la interfaz.

### 3.2 Usar el token

Dos cabeceras en **todas** las peticiones autenticadas:

```http
Authorization: Bearer 1|k7DQi02tEeugCsuiYjO5eUHa6GXRT5Hd5K8hJKu479db
Accept: application/json
```

> **`Accept: application/json` no es opcional.** Sin él, algunos errores pueden
> volver como HTML en lugar de JSON y tu cliente no sabrá interpretarlos.
> Mándalo siempre, también en los `GET`.

En las peticiones con cuerpo (`POST`, `PATCH`, `PUT`) añade además:

```http
Content-Type: application/json
```

### 3.3 Cerrar sesión

```http
POST /api/logout
```

Revoca **solo el token de esa petición**. Si la misma persona tiene sesión
abierta en otro dispositivo, esa sigue viva. Cada `login` genera un token nuevo
sin invalidar los anteriores.

### 3.4 Recuperar la sesión al recargar

```http
GET /api/me
```

Devuelve `{ "user": { ... } }`. El patrón habitual en un frontend: guardas el
token, y al arrancar la aplicación llamas a `/api/me` para saber si sigue
siendo válido y recuperar el rol.

### 3.5 Cuándo falla el login

| Situación | Respuesta |
|---|---|
| Email o contraseña incorrectos | `422` · `errors.email`: *"Las credenciales no coinciden con nuestros registros."* |
| Usuario **desactivado** | `422` · `errors.email`: *"Tu cuenta esta desactivada. Contacta a un administrador."* |
| Más de 6 intentos por minuto | `429` |

Un detalle deliberado: un correo **inexistente** y una contraseña **incorrecta**
devuelven exactamente el mismo mensaje. Si fueran distintos, cualquiera podría
averiguar qué correos están registrados probándolos uno a uno. No intentes
distinguir esos dos casos en tu interfaz, porque la API no te lo dirá.

---

## 4. Forma de las respuestas

### 4.1 Un recurso

```json
{
  "data": { "id": 1, "nombre": "..." }
}
```

### 4.2 Un listado

```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "total": 42
  }
}
```

Siempre `data` + `meta`, en todos los listados sin excepción.

### 4.3 Paginación

Todos los listados aceptan `?per_page=` y `?page=`:

- `per_page` por defecto **15**, acotado a **[1, 100]**. Si mandas `0`, `-5` o
  `99999`, el servidor lo ajusta al rango en vez de fallar.
- Para saber si hay más páginas, compara `meta.current_page` con
  `meta.last_page`.

```
GET /api/equipos?per_page=50&page=2
```

### 4.4 Fechas

Dos zonas horarias conviven, y conviene saber cuál esperas:

| Dónde | Zona | Ejemplo |
|---|---|---|
| Fechas de **préstamos** y de **revisiones** | Negocio, Colombia (`-05:00`) | `2026-11-17T16:30:00-05:00` |
| `created_at`/`updated_at` de otras entidades, historial de equipos | UTC (`Z`) | `2026-11-17T21:30:00.000000Z` |

Ambas son ISO 8601 válidas, así que `new Date(...)` en JavaScript las interpreta
bien sin que hagas nada.

**Al enviar fechas**, el formato es estricto. Se aceptan:

```
2026-11-17 16:30           ← se interpreta en hora de Colombia
2026-11-17 16:30:00
2026-11-17T16:30:00
2026-11-17T21:30:00Z       ← ISO con zona explícita
2026-11-17T16:30:00-05:00
```

Se **rechazan** con `422`: fechas sin hora (`2026-11-17`), con barras
(`17/11/2026`) y expresiones relativas (`mañana`).

> **Recomendación:** manda siempre ISO con zona explícita (`Z` o `-05:00`). Una
> fecha sin zona se interpreta como hora de Colombia, y si tu cliente está en
> otro huso vas a tener desfases difíciles de depurar. En JavaScript,
> `new Date().toISOString()` ya te da el formato correcto.

### 4.5 Estados y etiquetas

Cada estado viaja por duplicado: el valor y su texto legible. No traduzcas tú.

```json
{
  "estado": "con_danos",
  "estado_etiqueta": "Con daños"
}
```

Usa `estado` para tu lógica y `estado_etiqueta` para mostrar.

---

## 5. Roles

Tres roles, **uno por persona**:

| Rol | Puede |
|---|---|
| `usuario` | Ver el catálogo, solicitar préstamos, ver **solo los suyos** |
| `encargado` | Todo lo anterior + gestionar el ciclo de préstamos de cualquiera |
| `admin` | Todo + gestionar usuarios y el catálogo de equipos |

En la documentación verás el término **personal administrativo**: significa
`admin` **o** `encargado`. Es el que decide la mayoría de los permisos.

### Un comportamiento que te va a sorprender si no lo sabes

Cuando pides un recurso que **existe pero no te corresponde**, la API responde
**`404`, no `403`**:

- Un `usuario` pidiendo el préstamo de otra persona → `404`
- Un `usuario` pidiendo un equipo dado de baja → `404`

Es a propósito: un `403` confirmaría que el recurso existe, y eso ya es
información. Un préstamo ajeno y un id inexistente devuelven respuestas
**idénticas**.

En tu interfaz, trata ambos casos como "no encontrado" y no intentes deducir
nada del `404`.

---

## 6. Errores

### 6.1 Dos formatos

**Validación (`422`)** — trae `errors` con el detalle por campo:

```json
{
  "message": "El equipo seleccionado no se encuentra disponible para préstamo.",
  "errors": {
    "equipo_id": ["El equipo seleccionado no se encuentra disponible para préstamo."]
  }
}
```

**Todo lo demás** — solo `message`:

```json
{ "message": "No tienes permiso para realizar esta acción." }
```

### 6.2 Códigos

| Código | Significado | Qué hacer |
|---|---|---|
| `401` | *No autenticado.* Falta el token, es inválido o fue revocado | Mandar al login |
| `403` | *No tienes permiso para realizar esta acción.* Tu rol no alcanza | Ocultar la acción en la interfaz |
| `404` | *Recurso no encontrado.* No existe **o** no te corresponde | Tratar como inexistente |
| `405` | *Método no permitido.* Verbo HTTP equivocado | Revisar tu código |
| `422` | Validación o regla de negocio | **Mostrar `errors` al usuario** |
| `429` | *Demasiadas solicitudes.* Límite alcanzado | Esperar y reintentar |
| `500` | *Error interno del servidor.* | Reportar |

### 6.3 Lo importante sobre el 422

**Las reglas de negocio también son `422`**, no solo los campos mal formados.
"El equipo está en mantenimiento" o "Has alcanzado el límite de 3 préstamos
activos" llegan igual que un campo obligatorio vacío, con la clave del campo
responsable (`equipo_id`, `usuario_id`, `estado`, `equipo`,
`fecha_devolucion_real`...).

Esto es cómodo: **si pintas `errors` tal cual junto a cada campo, cubres a la vez
la validación y las reglas de negocio**. Los mensajes ya vienen en español y
redactados para el usuario final.

### 6.4 Límites de frecuencia

| Endpoint | Límite |
|---|---|
| `POST /api/login` | 6 por minuto y por IP |
| `POST /api/prestamos` | 10 por minuto |

Al pasarse: `429`, con las cabeceras estándar de reintento.

---

## 7. Catálogo de endpoints

### Autenticación

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/api/ping` | — |
| `POST` | `/api/login` | — |
| `POST` | `/api/logout` | autenticado |
| `GET` | `/api/me` | autenticado |

### Equipos

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/api/equipos` | autenticado |
| `GET` | `/api/equipos/{id}` | autenticado |
| `GET` | `/api/equipos/{id}/historial` | autenticado |
| `GET` | `/api/equipos/{id}/revisiones` | admin, encargado |
| `POST` | `/api/equipos` | admin |
| `PATCH` | `/api/equipos/{id}/estado` | admin |

Filtros de `GET /api/equipos`:

| Parámetro | Ejemplo |
|---|---|
| `categoria_id` | `?categoria_id=1` |
| `disponible` | `?disponible=1` |
| `buscar` | `?buscar=laptop` (busca en nombre, código y descripción) |
| `ordenar` | `nombre` (default), `-nombre`, `disponibles_primero`, `recientes` |

### Usuarios

| Método | Ruta | Rol |
|---|---|---|
| `GET` `POST` | `/api/usuarios` | admin |
| `GET` `PUT` | `/api/usuarios/{id}` | admin |
| `PATCH` | `/api/usuarios/{id}/activar` | admin |
| `PATCH` | `/api/usuarios/{id}/desactivar` | admin |

No hay borrado: los usuarios se **desactivan**. Y un admin no puede
desactivarse a sí mismo (`422`), para que nadie se deje fuera sin remedio.

### Préstamos

| Método | Ruta | Rol |
|---|---|---|
| `GET` | `/api/prestamos` | autenticado |
| `POST` | `/api/prestamos` | autenticado |
| `GET` | `/api/prestamos/{id}` | propietario, admin, encargado |
| `GET` | `/api/prestamos/pendientes` | admin, encargado |
| `GET` | `/api/prestamos/activos` | admin, encargado |
| `POST` | `/api/prestamos/{id}/aprobacion` | admin, encargado |
| `POST` | `/api/prestamos/{id}/rechazo` | admin, encargado |
| `POST` | `/api/prestamos/{id}/entrega` | admin, encargado |
| `POST` | `/api/prestamos/{id}/devolucion` | admin, encargado |

Filtros de `GET /api/prestamos`:

| Parámetro | Qué hace |
|---|---|
| `estado` | Un estado exacto. Valor inválido → `422` |
| `activos` | `1` solo los vivos, `0` solo los cerrados |

**`GET /api/prestamos` ya filtra por ti.** Si eres `usuario`, devuelve solo tus
préstamos, sin que tengas que pedirlo. Mandar `?usuario_id=` **no** sirve para
ver los de otra persona: el servidor impone el filtro.

> **Cuidado con la palabra "activos", que significa dos cosas:**
>
> | | Incluye |
> |---|---|
> | `?activos=1` | `solicitado` + `aprobado` + `entregado` |
> | `/api/prestamos/activos` | solo `entregado` |
>
> El endpoint responde *"¿qué equipos están fuera ahora mismo?"*; el filtro,
> *"¿qué préstamos míos siguen vigentes?"*. Para la pantalla "mis préstamos" de
> un solicitante quieres el **filtro**, no el endpoint (que además es solo para
> personal).

---

## 8. El ciclo de vida de un préstamo

```
                    ┌─→ rechazado (fin)
                    │
  solicitado ───────┼─→ cancelado (fin)
                    │
                    └─→ aprobado ──┬─→ cancelado (fin)
                                   │
                                   └─→ entregado ──→ devuelto (fin)
```

| Paso | Endpoint | Qué le pasa al equipo |
|---|---|---|
| Solicitar | `POST /prestamos` | **Nada**: reservar no es ocupar |
| Aprobar | `POST /prestamos/{id}/aprobacion` | Nada todavía |
| Rechazar | `POST /prestamos/{id}/rechazo` | Nada; libera el cupo |
| Entregar | `POST /prestamos/{id}/entrega` | Pasa a `en_prestamo` |
| Devolver | `POST /prestamos/{id}/devolucion` | `bueno` → `disponible`; con novedad → `mantenimiento` |

`cancelado` está previsto en el modelo pero **todavía no tiene endpoint**.

Si intentas un paso desde el estado equivocado, recibes `422` con un mensaje
explícito: *"El préstamo está en estado Aprobado y no puede pasar a Devuelto."*

### Reglas que te van a rechazar una solicitud

Al crear un préstamo (`POST /api/prestamos`):

1. El equipo debe estar **`disponible`**. En mantenimiento o dado de baja → `422`.
2. Máximo **3 préstamos activos** por persona (configurable). Los rechazados y
   devueltos no cuentan: liberan cupo.
3. Duración máxima **7 días** (configurable).
4. `fecha_inicio` no puede estar en el pasado (con 60 segundos de tolerancia).
5. `fecha_devolucion_estimada` posterior a `fecha_inicio`.
6. **Sin solapamiento**: no puede haber otro préstamo vivo del mismo equipo en
   ese rango de fechas.
7. `motivo` obligatorio, máximo 1000 caracteres, no puede ser solo espacios.

### Al devolver

`condicion_devolucion` es obligatoria: `bueno`, `con_danos` o
`requiere_mantenimiento`. **Si no es `bueno`, `observaciones` pasa a ser
obligatoria**: si algo volvió mal, hay que decir qué.

La devolución crea además una **revisión** (una por préstamo), consultable en el
campo `revision` del préstamo y en `/api/equipos/{id}/revisiones`.

---

## 9. Qué campos ve cada rol

El mismo préstamo se ve distinto según quién pregunte. Las **claves siempre
existen**; lo que cambia es si traen contenido.

| Campo | Lo ve |
|---|---|
| `observaciones_entrega`, `observaciones_devolucion` | Solo personal |
| `revision.observaciones` | Solo personal |
| `motivo_rechazo` | Personal **y** el solicitante dueño |
| `solicitante.email`, `.is_active`, `.roles` | Solo personal |
| `usuario_gestion`, `usuario_entrega`, `usuario_recepcion` | Siempre `{id, name}`, nunca más |
| `equipo.observaciones` | Solo personal |

No programes tu interfaz asumiendo que un campo estará: compruébalo.

---

## 10. Receta completa

### Con `curl`

```bash
BASE=http://127.0.0.1:8000/api

# 1. Token
TOKEN=$(curl -s -X POST $BASE/login \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"email":"test@example.com","password":"password"}' \
  | jq -r .token)

AUTH="-H Authorization:Bearer${TOKEN} -H Accept:application/json -H Content-Type:application/json"

# 2. Crear un equipo (admin)
curl -s -X POST $BASE/equipos $AUTH \
  -d '{"codigo":"PORT-001","nombre":"Laptop Dell","categoria_id":1}'

# 3. Solicitar
curl -s -X POST $BASE/prestamos $AUTH -d '{
  "equipo_id": 1,
  "motivo": "Práctica de laboratorio",
  "fecha_inicio": "2026-11-20T09:00:00-05:00",
  "fecha_devolucion_estimada": "2026-11-22T17:00:00-05:00"
}'

# 4. Aprobar, entregar, devolver
curl -s -X POST $BASE/prestamos/1/aprobacion $AUTH
curl -s -X POST $BASE/prestamos/1/entrega $AUTH \
  -d '{"condicion_entrega":"bueno","observaciones":"Con cargador"}'
curl -s -X POST $BASE/prestamos/1/devolucion $AUTH \
  -d '{"condicion_devolucion":"con_danos","observaciones":"Bisagra rota"}'
```

### Con JavaScript

Un cliente mínimo que centraliza cabeceras y errores:

```js
const BASE = 'http://127.0.0.1:8000/api';

class ApiError extends Error {
  constructor(status, message, errors) {
    super(message);
    this.status = status;
    this.errors = errors ?? {};   // { campo: [mensajes] }
  }
}

async function api(ruta, { method = 'GET', body, token } = {}) {
  const res = await fetch(`${BASE}${ruta}`, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  });

  // 204 u otra respuesta sin cuerpo
  const payload = res.status === 204 ? null : await res.json();

  if (!res.ok) {
    throw new ApiError(res.status, payload?.message ?? 'Error', payload?.errors);
  }
  return payload;
}
```

Uso:

```js
// Login
const { token, user } = await api('/login', {
  method: 'POST',
  body: { email: 'test@example.com', password: 'password', device_name: 'web' },
});

// Mis préstamos vigentes
const { data: mios } = await api('/prestamos?activos=1', { token });

// Solicitar, mostrando los errores donde corresponde
try {
  const { data: prestamo } = await api('/prestamos', {
    method: 'POST',
    token,
    body: {
      equipo_id: 1,
      motivo: 'Práctica de laboratorio',
      fecha_inicio: new Date(Date.now() + 3600e3).toISOString(),
      fecha_devolucion_estimada: new Date(Date.now() + 48 * 3600e3).toISOString(),
    },
  });
} catch (e) {
  if (e.status === 422) {
    // e.errors = { equipo_id: ["El equipo seleccionado..."] }
    // Píntalos junto a cada campo: ya vienen en español.
    mostrarErroresEnFormulario(e.errors);
  } else if (e.status === 401) {
    irAlLogin();
  } else {
    mostrarAviso(e.message);
  }
}
```

---

## 11. Pantallas típicas y qué llamar

| Pantalla | Llamada |
|---|---|
| Catálogo para solicitar | `GET /equipos?disponible=1&ordenar=disponibles_primero` |
| Buscador de equipos | `GET /equipos?buscar=<texto>` |
| "Mis préstamos" (solicitante) | `GET /prestamos` |
| "Mis préstamos vigentes" | `GET /prestamos?activos=1` |
| "Mi historial" | `GET /prestamos?activos=0` |
| Bandeja de solicitudes por atender | `GET /prestamos/pendientes` |
| Equipos que están fuera ahora | `GET /prestamos/activos` |
| Buscar quién tiene un equipo | `GET /prestamos/activos?buscar=PORT-001` |
| Ficha de un equipo con su historial | `GET /equipos/{id}` + `GET /equipos/{id}/historial` |
| Novedades de un equipo | `GET /equipos/{id}/revisiones?con_danos=1` |

`GET /prestamos/pendientes` ordena de la solicitud **más antigua a la más
reciente**: es el orden de atención. El resto de listados van al revés, primero
lo más nuevo.

---

## 12. Errores frecuentes al integrar

| Síntoma | Causa |
|---|---|
| Recibes HTML en vez de JSON | Falta `Accept: application/json` |
| `404` en `/api` | `/api` es un prefijo, no un endpoint |
| `401` con un token que ayer servía | Se hizo `logout` en ese dispositivo |
| `405` en el login | Lo estás pidiendo con `GET`; es `POST` |
| `429` al probar | Límite de 6 logins por minuto; espera |
| Fechas con horas desfasadas | Mandaste fecha sin zona y se interpretó como Colombia |
| `422` en `fecha_inicio` que parece correcta | Falta la hora: `2026-11-17` no vale |
| Un `404` donde esperabas `403` | Es deliberado: el recurso existe pero no te corresponde |
| `?usuario_id=` no filtra | El filtro por usuario lo impone el servidor |
| No ves `observaciones_*` | Tu rol no es personal administrativo |

---

## 13. Levantar la API en local

```bash
docker compose up -d                                   # backend + MySQL
docker compose exec prestamos_backend php artisan db:seed
```

Queda en `http://127.0.0.1:8000`, con un usuario sembrado:

```
test@example.com / password      (rol admin)
```

Para probar a mano hay una colección de Postman lista en
[../postman/prestamos-ecci.postman_collection.json](../postman/prestamos-ecci.postman_collection.json),
con el token guardándose solo tras el login y el ciclo completo de un préstamo
encadenado.

> **No hay Swagger ni OpenAPI** en este proyecto: la documentación es esta
> carpeta más la colección de Postman.
