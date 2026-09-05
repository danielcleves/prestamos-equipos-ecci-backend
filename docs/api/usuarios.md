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
| `PUT` / `PATCH` | `/api/usuarios/{usuario}` | Modifica nombre, correo, contraseña y/o rol |
| `PATCH` | `/api/usuarios/{usuario}/activar` | Reactiva la cuenta |
| `PATCH` | `/api/usuarios/{usuario}/desactivar` | Desactiva la cuenta (bloquea el login) |
| `DELETE` | `/api/usuarios/{usuario}` | Elimina el usuario (soft delete, ver abajo) |

## Roles disponibles

`admin`, `encargado`, `usuario` (decisión cerrada, ver `CONTEXTO-PROYECTO.md`
sección 3 — corresponden a administrador / personal de préstamo / solicitante
de la HU-02). Cada usuario tiene **un solo rol a la vez**: `PUT/PATCH` con
`role` reemplaza el rol anterior, no lo acumula.

## Reglas

- Sin token o sin rol `admin` → `401`/`403`.
- Un admin **no puede desactivarse ni eliminarse a sí mismo** (`422`), para
  no quedar sin forma de revertirlo.
- Un usuario con `is_active = false` no puede iniciar sesión (ver
  `docs/api/autenticacion.md`), aunque su token previo siga siendo válido
  hasta que expire o se revoque — desactivar no cierra sesiones activas.
- **Eliminar es soft delete** (`deleted_at`), no borrado físico: el registro
  sigue en la base de datos (por si más adelante tiene préstamos u otro
  historial asociado), pero desaparece de `GET /api/usuarios` y de
  `GET /api/usuarios/{usuario}` (404), y ya no puede iniciar sesión.

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
    "role": "encargado"
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
    "roles": ["encargado"]
  }
}
```

**Cambiar el rol de un usuario**

```sh
curl -i -X PATCH http://localhost:8000/api/usuarios/5 \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"role": "usuario"}'
```

**Desactivar un usuario**

```sh
curl -i -X PATCH http://localhost:8000/api/usuarios/5/desactivar \
  -H "Authorization: Bearer <token-admin>"
```

**Eliminar un usuario (soft delete)**

```sh
curl -i -X DELETE http://localhost:8000/api/usuarios/5 \
  -H "Authorization: Bearer <token-admin>"
# 204 No Content
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
