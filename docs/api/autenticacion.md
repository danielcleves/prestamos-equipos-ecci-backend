# Autenticación

La API usa **Laravel Sanctum en modo tokens (Bearer)**, no cookies/SPA: el
cliente manda `Authorization: Bearer <token>` en cada petición a una ruta
protegida.

## Endpoints

| Método | Endpoint | Auth | Descripción |
|---|---|---|---|
| `POST` | `/api/login` | No | Valida credenciales y emite un token |
| `POST` | `/api/logout` | Sí | Revoca **solo** el token usado en la petición |
| `GET` | `/api/me` | Sí | Devuelve el usuario autenticado (id, name, email, roles) |

## Reglas a tener en cuenta

- `/api/login` está limitado a **6 intentos por minuto por IP** (`throttle:6,1`); el séptimo intento devuelve `429`.
- Credenciales inválidas y correo inexistente devuelven **el mismo mensaje de error**, para no dejar averiguar qué correos están registrados.
- Un usuario con `is_active = false` no puede iniciar sesión aunque la contraseña sea correcta: devuelve `422` con un mensaje distinto ("Tu cuenta esta desactivada..."). Hoy ese campo solo se puede cambiar por base de datos/Tinker; el endpoint para administrarlo llega con la gestión de usuarios.
- Todos los errores de la API siguen el formato `{ "message": "...", "errors": {...} }`.

## Respuestas de ejemplo

**`POST /api/login` — éxito (200)**

```json
{
  "token": "1|abcdef123456...",
  "user": {
    "id": 1,
    "name": "Test User",
    "email": "test@example.com",
    "roles": ["encargado"]
  }
}
```

**`POST /api/login` — credenciales inválidas o correo inexistente (422)**

```json
{
  "message": "Las credenciales no coinciden con nuestros registros.",
  "errors": {
    "email": ["Las credenciales no coinciden con nuestros registros."]
  }
}
```

**`POST /api/login` — usuario desactivado (422)**

```json
{
  "message": "Tu cuenta esta desactivada. Contacta a un administrador.",
  "errors": {
    "email": ["Tu cuenta esta desactivada. Contacta a un administrador."]
  }
}
```

**Cualquier ruta protegida sin token válido (401)**

```json
{
  "message": "Unauthenticated."
}
```

## Probar con curl

```sh
# 1. Login (guarda el token de la respuesta)
curl -i -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}'

# 2. Ruta protegida
curl -i http://localhost:8000/api/me \
  -H "Authorization: Bearer <token>"

# 3. Logout
curl -i -X POST http://localhost:8000/api/logout \
  -H "Authorization: Bearer <token>"
```

## Probar con Postman

1. Crea un **Environment** con `base_url = http://localhost:8000`.
2. `POST {{base_url}}/api/login` con body JSON `{"email":"test@example.com","password":"password"}`. En la pestaña **Tests**, agrega `pm.environment.set("token", pm.response.json().token);` para capturar el token automáticamente.
3. En `/api/me` y `/api/logout`, usa **Authorization → Bearer Token → `{{token}}`**.
4. El usuario de prueba (`test@example.com` / `password`) lo crea el seeder: `docker compose exec prestamos_backend php artisan db:seed`.

## Cómo probar el caso de usuario desactivado

Todavía no hay endpoint para activar/desactivar usuarios (llega con la
gestión de usuarios y roles), así que se hace por Tinker:

```sh
# Desactivar
docker compose exec prestamos_backend php artisan tinker --execute="App\Models\User::where('email','test@example.com')->update(['is_active' => false]);"

# Reactivar
docker compose exec prestamos_backend php artisan tinker --execute="App\Models\User::where('email','test@example.com')->update(['is_active' => true]);"
```
