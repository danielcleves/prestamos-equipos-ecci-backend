# Catálogo de equipos

Implementa **HU-03** ("registrar equipos para mantener actualizado el
catálogo") y **HU-04** ("actualizar el estado de un equipo").

## Endpoints

| Método | Endpoint | Auth | Descripción |
|---|---|---|---|
| `GET` | `/api/equipos` | Cualquier autenticado | Lista el catálogo, paginado (`?per_page=`, default 15, máx 100) |
| `GET` | `/api/equipos/{equipo}` | Cualquier autenticado | Consulta un equipo puntual |
| `GET` | `/api/equipos/{equipo}/historial` | Cualquier autenticado | Historial de cambios de `estado`, más reciente primero |
| `POST` | `/api/equipos` | Solo `admin` | Registra un equipo nuevo |
| `PATCH` | `/api/equipos/{equipo}/estado` | Solo `admin` | Cambia el `estado` del equipo |

La consulta está abierta a cualquier rol autenticado (no solo `admin`) porque
HU-05 ("consultar los equipos disponibles") va a necesitarlo para que
solicitantes vean el catálogo — esa historia es quien agrega filtros como
"solo disponibles" más adelante, esto solo expone el listado base.

## Campos de un equipo

| Campo | Tipo | Regla |
|---|---|---|
| `codigo` | string, único | "Código o serial" del equipo — lo pide el admin al registrar |
| `nombre` | string | requerido |
| `categoria_id` | id de una categoría existente | requerido — ver `docs/decisiones.md` para las categorías de ejemplo |
| `descripcion` | string, opcional | |
| `estado` | `disponible` / `en_prestamo` / `mantenimiento` / `dado_de_baja` | **al registrar, el sistema lo asigna solo** (`disponible`) — no es un campo de entrada de `POST`, cualquier valor enviado ahí se ignora. Se cambia después con `PATCH /api/equipos/{equipo}/estado` |
| `observaciones` | string, opcional | |

`categoria_id` no tiene su propio CRUD todavía — se siembra con
`CategoriaSeeder` (`Portátil`, `Tablet`, `De mesa`) hasta que exista una HU
para administrar categorías.

## Reglas

- `codigo` es único — registrar uno duplicado devuelve `422`.
- `categoria_id` debe existir en `categorias` — si no, `422`.
- El `estado` que mande el cliente en `POST /api/equipos` se ignora: el
  sistema siempre asigna `disponible` a un equipo recién creado.
- **`dado_de_baja` es un estado terminal**: un equipo en ese estado no puede
  volver a cambiar de estado (`PATCH .../estado` devuelve `422`). Así se
  cumple que un equipo dado de baja nunca puede llegar a `en_prestamo`.
- Cada cambio de `estado` (incluida la asignación inicial al registrar)
  queda registrado en `historial_estados`, con quién lo hizo y cuándo —
  consultable en `GET /api/equipos/{equipo}/historial`.
- `mantenimiento` y `dado_de_baja` no filtran nada todavía en
  `GET /api/equipos` — HU-05 ("consultar equipos disponibles") es quien
  agrega ese filtro; por ahora el catálogo lista todos los equipos sin
  importar su estado.

## Ejemplos

**Registrar un equipo**

```sh
curl -i -X POST http://localhost:8000/api/equipos \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{
    "codigo": "EQ-001",
    "nombre": "Portátil Dell 14\"",
    "categoria_id": 1,
    "descripcion": "Core i5, 8GB RAM",
    "observaciones": "Con cargador original"
  }'
```

Respuesta (`201`):

```json
{
  "data": {
    "id": 1,
    "codigo": "EQ-001",
    "nombre": "Portátil Dell 14\"",
    "categoria": { "id": 1, "nombre": "Portátil" },
    "descripcion": "Core i5, 8GB RAM",
    "estado": "disponible",
    "observaciones": "Con cargador original"
  }
}
```

**Listar el catálogo**

```sh
curl -i http://localhost:8000/api/equipos \
  -H "Authorization: Bearer <token>"
```

**Cambiar el estado de un equipo**

```sh
curl -i -X PATCH http://localhost:8000/api/equipos/1/estado \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"estado": "mantenimiento"}'
```

**Consultar el historial de estados**

```sh
curl -i http://localhost:8000/api/equipos/1/historial \
  -H "Authorization: Bearer <token>"
```

```json
{
  "data": [
    {
      "id": 2,
      "estado_anterior": "disponible",
      "estado_nuevo": "mantenimiento",
      "usuario": { "id": 1, "name": "Test User" },
      "fecha": "2026-09-09T19:50:00.000000Z"
    },
    {
      "id": 1,
      "estado_anterior": null,
      "estado_nuevo": "disponible",
      "usuario": { "id": 1, "name": "Test User" },
      "fecha": "2026-09-09T19:40:00.000000Z"
    }
  ]
}
```

## Pendiente

- Filtrar el catálogo por disponibilidad (HU-05).
- CRUD de `categorias` — hoy solo existen las que trae `CategoriaSeeder`.
