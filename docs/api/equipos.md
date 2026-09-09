# Catálogo de equipos

Implementa **HU-03** ("Como administrador, quiero registrar equipos para
mantener actualizado el catálogo").

## Endpoints

| Método | Endpoint | Auth | Descripción |
|---|---|---|---|
| `GET` | `/api/equipos` | Cualquier autenticado | Lista el catálogo, paginado (`?per_page=`, default 15, máx 100) |
| `GET` | `/api/equipos/{equipo}` | Cualquier autenticado | Consulta un equipo puntual |
| `POST` | `/api/equipos` | Solo `admin` | Registra un equipo nuevo |

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
| `estado` | `disponible` / `en_prestamo` / `mantenimiento` | **el sistema lo asigna solo** (`disponible` al registrar) — no es un campo de entrada, cualquier valor que se envíe en `POST` se ignora |
| `observaciones` | string, opcional | |

`categoria_id` no tiene su propio CRUD todavía — se siembra con
`CategoriaSeeder` (`Portátil`, `Tablet`, `De mesa`) hasta que exista una HU
para administrar categorías.

## Reglas

- `codigo` es único — registrar uno duplicado devuelve `422`.
- `categoria_id` debe existir en `categorias` — si no, `422`.
- El `estado` que mande el cliente en `POST /api/equipos` se ignora: el
  sistema siempre asigna `disponible` a un equipo recién creado.

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

## Pendiente

- Actualizar/transicionar el `estado` de un equipo (HU-04).
- Filtrar el catálogo por disponibilidad (HU-05).
- CRUD de `categorias` — hoy solo existen las que trae `CategoriaSeeder`.
