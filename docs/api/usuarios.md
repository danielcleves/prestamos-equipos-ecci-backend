# Gestión de usuarios y roles

Todos los endpoints de este grupo requieren estar autenticado (Sanctum) **y**
tener el rol `admin` — es exclusivo para HU-02 ("Como administrador, quiero
gestionar usuarios y roles").

## Endpoints

| Método | Endpoint | Descripción |
|---|---|---|
| `GET` | `/api/usuarios` | Lista usuarios, paginada (`?per_page=`, default 15, máx 100) |
| `POST` | `/api/usuarios` | Registra un usuario nuevo (requiere `role`) |
| `GET` | `/api/usuarios/{usuario}` | Consulta un usuario puntual |
| `PUT` / `PATCH` | `/api/usuarios/{usuario}` | Modifica nombre, correo, contraseña, rol y/o capacitaciones |
| `PATCH` | `/api/usuarios/{usuario}/activar` | Reactiva la cuenta |
| `PATCH` | `/api/usuarios/{usuario}/desactivar` | Desactiva la cuenta (bloquea el login) |
| `GET` | `/api/usuarios/{usuario}/sanciones` | Sanciones por mora del usuario (RN-04), paginadas; activas primero. También para `encargado` |

## Roles disponibles

Cualquier rol existente (ver [`roles.md`](roles.md)): los de sistema `admin`
(administrador) y `encargado` (personal de préstamo), y los de solicitante
—`estudiante` por defecto y los que cree el admin (`profesor`,
`coordinador`…)—. Cada usuario tiene **un solo rol a la vez**: `PUT/PATCH` con
`role` reemplaza el rol anterior, no lo acumula. El rol decide el cupo de
préstamos vivos (RN-02).

## Reglas

- Sin token o sin rol `admin` → `401`/`403`.
- Un admin **no puede desactivarse a sí mismo** (`422`), para no quedar sin
  forma de revertirlo.
- Un usuario con `is_active = false` no puede iniciar sesión (ver
  `docs/api/autenticacion.md`), aunque su token previo siga siendo válido
  hasta que expire o se revoque — desactivar no cierra sesiones activas.

## Capacitación por categoría (RN-01)

La capacitación es **por categoría de equipo**: estar capacitado en
«Routers» no habilita «Servidores». Cada categoría decide si la exige
(`requiere_capacitacion`, ver más abajo); si la exige y el usuario no está
capacitado en ella, `POST /api/prestamos` (y la entrega) responde `422` en
`usuario_id` con «Debes estar capacitado en «{categoría}» para solicitar este
equipo.». No hay excepción para `admin` ni `encargado`.

- **`capacitaciones`** (opcional en `POST` y `PUT/PATCH`): lista de ids de
  categoría (`array`; cada uno entero, sin repetir y existente en
  `categorias`, si no `422`).
  - En `POST`, si no viene, el usuario queda **sin capacitaciones**.
  - En `PUT/PATCH`, si viene, **reemplaza** la lista completa (`[]` las
    retira todas): las que ya tenía conservan su fecha y quién las otorgó, y
    las nuevas quedan a nombre del admin autenticado. Si no viene, no se
    tocan.
- La respuesta de usuario trae **`capacitaciones`**: lista ordenada por
  nombre de `{ categoria_id, nombre, otorgada_en }` (`otorgada_en` en ISO 8601,
  hora de Colombia). La capacitación no vence.
- El auto-registro (`POST /api/register`) crea la cuenta sin capacitaciones.
- Reemplaza al booleano `capacitado` (retirado; si se envía, se ignora) y al
  parámetro global `elegibilidad.requiere_capacitacion` (retirado; `PUT
  /api/configuracion` lo rechaza con `422`).

**Exigir capacitación en una categoría** (solo `admin`):

```sh
curl -i -X PATCH http://localhost:8000/api/categorias/4/restriccion \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"requiere_capacitacion": true}'
```

Acepta `nivel_restriccion` (0, 1 o 2) y/o `requiere_capacitacion` (booleano);
ambos son opcionales pero debe venir al menos uno (`422` si no). Responde la
categoría con `id`, `nombre`, `nivel_restriccion` y `requiere_capacitacion`.
`GET /api/configuracion` expone `requiere_capacitacion` en cada elemento de
`data.categorias`.

## Ejemplos

**Registrar usuario**

```sh
curl -i -X POST http://localhost:8000/api/usuarios \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Ana Pérez",
    "email": "ana@ecci.edu.co",
    "password": "password123",
    "role": "encargado",
    "capacitaciones": [4]
  }'
```

Respuesta (`201`):

```json
{
  "data": {
    "id": 5,
    "name": "Ana Pérez",
    "email": "ana@ecci.edu.co",
    "is_active": true,
    "capacitaciones": [
      { "categoria_id": 4, "nombre": "Routers", "otorgada_en": "2026-11-25T10:30:00-05:00" }
    ],
    "roles": ["encargado"],
    "rol_principal": "encargado",
    "rol_etiqueta": "Personal de préstamo",
    "sancion_activa": null
  }
}
```

**Cambiar el rol de un usuario**

```sh
curl -i -X PATCH http://localhost:8000/api/usuarios/5 \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"role": "profesor"}'
```

**Desactivar un usuario**

```sh
curl -i -X PATCH http://localhost:8000/api/usuarios/5/desactivar \
  -H "Authorization: Bearer <token-admin>"
```

**Listar con paginación**

```sh
curl -i "http://localhost:8000/api/usuarios?per_page=5" \
  -H "Authorization: Bearer <token-admin>"
```

```json
{
  "data": [ /* hasta 5 usuarios */ ],
  "meta": { "current_page": 1, "last_page": 4, "per_page": 5, "total": 20 }
}
```

## Pendiente

- No hay endpoint para eliminar usuarios — la HU-02 solo pide consultar,
  registrar, modificar, activar/desactivar y asignar rol. Se puede
  desactivar en su lugar. Si más adelante se pide eliminar, lo más prudente
  es soft delete (`deleted_at`) en vez de borrado físico, para no dejar
  huérfano el historial que un usuario pueda tener (préstamos, etc.).

## Sanciones por mora (RN-04)

`GET /api/usuarios/{usuario}/sanciones` devuelve las sanciones del usuario,
paginadas (`?per_page=`, default 15, máx 100) y con las **activas primero**
(`estado = activa` y `fecha_fin` no vencida). Disponible para `admin` y
`encargado`.

```json
{
  "data": [
    {
      "id": 1,
      "user_id": 7,
      "prestamo_id": 12,
      "motivo": "mora",
      "dias_retraso": 1,
      "dias_sancion": 3,
      "dias_restantes": 3,
      "fecha_inicio": "2026-12-09T12:00:00-05:00",
      "fecha_fin": "2026-12-12T12:00:00-05:00",
      "estado": "activa",
      "vigente": true,
      "created_at": "2026-12-09T12:00:00-05:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

Cada usuario trae además **`sancion_activa`**: el mismo objeto (sin el
`meta`) de su sanción vigente, o `null` si no tiene. `dias_restantes` se
cuenta en días calendario de Colombia y vale al menos 1 si la sanción vence
hoy. Una sanción vencida nunca vuelve a `null` en `estado`: se considera
cumplida al consultarla, sin reescribir la fila.
