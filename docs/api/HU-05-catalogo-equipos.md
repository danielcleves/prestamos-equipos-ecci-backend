# Catálogo de equipos disponibles (HU-05)

Implementa **HU-05**: *"Como solicitante, quiero consultar los equipos disponibles para seleccionar uno que pueda solicitar"*.

Extiende la consulta de catálogo iniciada en HU-03 incorporando cálculo de disponibilidad, visibilidad segura por roles, filtros combinados y ordenamiento.

---

## 1. Endpoints

| Método | Endpoint | Autenticación | Rol permitido | Descripción |
|---|---|---|---|---|
| `GET` | `/api/equipos` | Bearer Token Sanctum | Cualquier autenticado (`admin`, `encargado`, `usuario`) | Listado paginado con filtros y ordenamiento |
| `GET` | `/api/equipos/{equipo}` | Bearer Token Sanctum | Cualquier autenticado | Detalle completo de un equipo puntual |

### Cabeceras requeridas

```http
Authorization: Bearer <token>
Accept: application/json
```

---

## 2. Parámetros de consulta (`GET /api/equipos`)

Todos los parámetros de filtro y ordenamiento son opcionales.

| Parámetro | Tipo | Valores permitidos | Descripción y validación |
|---|---|---|---|
| `categoria_id` | entero | ID existente en tabla `categorias` | Filtra por categoría. Si no existe en la base de datos, devuelve `422`. |
| `disponible` | string / booleano | `true`, `false`, `1`, `0` | Filtra por disponibilidad: `true`/`1` filtra equipos disponibles; `false`/`0` filtra equipos no disponibles. Si se envía otro valor (ej. `invalido`), devuelve `422`. |
| `buscar` | string | Máx. 100 caracteres | Búsqueda textual parcial insensible a mayúsculas sobre `nombre`, `codigo` y `descripcion`. Los caracteres `%` y `_` se buscan de forma **literal** (escapados automáticamente). |
| `ordenar` | string | `nombre`, `-nombre`, `disponibles_primero`, `recientes` | Criterio de ordenación. Por defecto aplica `nombre` ascendente. En todos los casos incluye `id` ascendente como desempate estable. |
| `per_page` | entero | 1 a 100 (default: 15) | Cantidad de registros por página. Se **normaliza** en el controlador con `max(1, min($val, 100))`, por lo que valores fuera de rango o inválidos no lanzan error 422. |

### Tratamiento de parámetros vacíos
Si el frontend envía un parámetro vacío (por ejemplo `categoria_id=` o `disponible=` al seleccionar la opción "Todos" en un selector), el backend lo trata como si no se hubiera enviado. No aplica filtro ni falla con error de validación.

---

## 3. Campos de la respuesta y reglas de disponibilidad

Cada equipo en `data` (o en el detalle puntual) expone los siguientes campos:

```json
{
  "id": 1,
  "codigo": "EQ-PORT-01",
  "nombre": "Portátil Dell Latitude 3420",
  "descripcion": "Intel Core i5, 16GB RAM, SSD 512GB",
  "estado": "disponible",
  "disponible": true,
  "puede_solicitarse": true,
  "estado_disponibilidad": "disponible",
  "observaciones": "Cargador original incluido",
  "categoria": {
    "id": 1,
    "nombre": "Portátil"
  }
}
```

### Explicación de campos clave:
- `estado`: Valor del estado físico del equipo (`disponible`, `en_prestamo`, `mantenimiento`, `dado_de_baja`).
- `disponible` (boolean): `true` única y exclusivamente cuando `estado === 'disponible'`. Cualquier otro estado (`en_prestamo`, `mantenimiento`, `dado_de_baja`) resulta en `false`.
- `puede_solicitarse` (boolean): `true` únicamente si el equipo puede ser elegido para un nuevo préstamo. Coincide con `disponible` y encapsula la regla para el frontend.
- `estado_disponibilidad` (string): Código de estado de disponibilidad para la interfaz:
  - `"disponible"` si `estado === 'disponible'`
  - `"no_disponible"` si el equipo no está disponible (`en_prestamo`, `mantenimiento`, `dado_de_baja`)

> [!IMPORTANT]
> **Nota para Frontend:**
> El botón de solicitar un equipo debe habilitarse basándose **únicamente en `puede_solicitarse`**. No recalcules la disponibilidad ni evalúes el campo `estado` en el cliente.

---

## 4. Regla de visibilidad por rol

El estado `dado_de_baja` representa un equipo retirado del servicio.

- **`admin` y `encargado`:** pueden ver equipos en estado `dado_de_baja` tanto en el listado general (`GET /api/equipos`) como en el detalle puntual (`GET /api/equipos/{id}`).
- **`usuario` (solicitante) y cualquier otro rol:** los equipos `dado_de_baja` **nunca aparecen** en el listado general, y consultar su detalle puntual devuelve `404 Recurso no encontrado.`, exactamente idéntico a consultar un ID inexistente. De esta forma no se revela la existencia del equipo.

---

## 5. Ejemplos reales de uso

### Ejemplo 1: Filtrar portátiles ordenados alfabéticamente
**Petición:**
```http
GET /api/equipos?categoria_id=1&ordenar=nombre HTTP/1.1
Host: localhost:8000
Authorization: Bearer 1|AbCdEf123456...
Accept: application/json
```

**Respuesta (`200 OK`):**
```json
{
  "data": [
    {
      "id": 1,
      "codigo": "EQ-PORT-01",
      "nombre": "Portátil Dell Latitude 3420",
      "descripcion": "Intel Core i5, 16GB RAM, SSD 512GB",
      "estado": "disponible",
      "disponible": true,
      "puede_solicitarse": true,
      "estado_disponibilidad": "disponible",
      "observaciones": "Cargador original incluido",
      "categoria": {
        "id": 1,
        "nombre": "Portátil"
      }
    },
    {
      "id": 2,
      "codigo": "EQ-PORT-02",
      "nombre": "Portátil Lenovo ThinkPad E14",
      "descripcion": "AMD Ryzen 5, 8GB RAM, SSD 256GB",
      "estado": "en_prestamo",
      "disponible": false,
      "puede_solicitarse": false,
      "estado_disponibilidad": "no_disponible",
      "observaciones": "En préstamo a laboratorio de redes",
      "categoria": {
        "id": 1,
        "nombre": "Portátil"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 2
  }
}
```

### Ejemplo 2: Solo disponibles, buscando "Dell", ordenados por más recientes
**Petición:**
```http
GET /api/equipos?disponible=true&buscar=Dell&ordenar=recientes HTTP/1.1
Host: localhost:8000
Authorization: Bearer 1|AbCdEf123456...
Accept: application/json
```

**Respuesta (`200 OK`):**
```json
{
  "data": [
    {
      "id": 4,
      "codigo": "EQ-DESK-01",
      "nombre": "Todo en Uno Dell OptiPlex 5490",
      "descripcion": "Intel Core i7, 16GB RAM, Pantalla 23.8\"",
      "estado": "disponible",
      "disponible": true,
      "puede_solicitarse": true,
      "estado_disponibilidad": "disponible",
      "observaciones": "Teclado y mouse USB incluidos",
      "categoria": {
        "id": 3,
        "nombre": "De mesa"
      }
    },
    {
      "id": 1,
      "codigo": "EQ-PORT-01",
      "nombre": "Portátil Dell Latitude 3420",
      "descripcion": "Intel Core i5, 16GB RAM, SSD 512GB",
      "estado": "disponible",
      "disponible": true,
      "puede_solicitarse": true,
      "estado_disponibilidad": "disponible",
      "observaciones": "Cargador original incluido",
      "categoria": {
        "id": 1,
        "nombre": "Portátil"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 2
  }
}
```

### Ejemplo 3: No disponibles con parámetro vacío ignorado
**Petición:**
```http
GET /api/equipos?disponible=false&categoria_id= HTTP/1.1
Host: localhost:8000
Authorization: Bearer 1|AbCdEf123456...
Accept: application/json
```

**Respuesta (`200 OK`):**
```json
{
  "data": [
    {
      "id": 2,
      "codigo": "EQ-PORT-02",
      "nombre": "Portátil Lenovo ThinkPad E14",
      "descripcion": "AMD Ryzen 5, 8GB RAM, SSD 256GB",
      "estado": "en_prestamo",
      "disponible": false,
      "puede_solicitarse": false,
      "estado_disponibilidad": "no_disponible",
      "observaciones": "En préstamo a laboratorio de redes",
      "categoria": {
        "id": 1,
        "nombre": "Portátil"
      }
    },
    {
      "id": 3,
      "codigo": "EQ-TAB-01",
      "nombre": "Tablet Samsung Galaxy Tab S7",
      "descripcion": "Pantalla 11\", 128GB almacenamiento",
      "estado": "mantenimiento",
      "disponible": false,
      "puede_solicitarse": false,
      "estado_disponibilidad": "no_disponible",
      "observaciones": "Mantenimiento preventivo de batería",
      "categoria": {
        "id": 2,
        "nombre": "Tablet"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 2
  }
}
```

### Ejemplo 4: Error de validación con mensajes en español (`422 Unprocessable Content`)
**Petición:**
```http
GET /api/equipos?categoria_id=999999&disponible=invalido HTTP/1.1
Host: localhost:8000
Authorization: Bearer 1|AbCdEf123456...
Accept: application/json
```

**Respuesta real (`422 Unprocessable Content`):**
```json
{
  "message": "El campo categoría seleccionado es inválido. (y 1 error más)",
  "errors": {
    "categoria_id": [
      "El campo categoría seleccionado es inválido."
    ],
    "disponible": [
      "El campo disponible seleccionado es inválido."
    ]
  }
}
```

### Ejemplo 5: Consulta de equipo dado de baja (Admin vs Usuario)

**Petición con token de Administrador (`admin`):**
```http
GET /api/equipos/1 HTTP/1.1
Host: localhost:8000
Authorization: Bearer <token-admin>
Accept: application/json
```

**Respuesta real para Administrador (`200 OK`):**
```json
{
  "data": {
    "id": 1,
    "codigo": "EQ-BAJA-01",
    "nombre": "Portátil HP ProBook 450 (Baja)",
    "categoria": {
      "id": 1,
      "nombre": "Portátil"
    },
    "descripcion": "Equipo fuera de servicio por daño en placa base",
    "disponible": false,
    "puede_solicitarse": false,
    "estado_disponibilidad": "no_disponible",
    "estado": "dado_de_baja",
    "observaciones": "Desincorporado según acta 2026-001"
  }
}
```

**Petición con token de Usuario Solicitante (`usuario`):**
```http
GET /api/equipos/1 HTTP/1.1
Host: localhost:8000
Authorization: Bearer <token-usuario>
Accept: application/json
```

**Respuesta real para Usuario (`404 Not Found`):**
```json
{
  "message": "Recurso no encontrado."
}
```
*(Idéntica respuesta a consultar un ID inexistente)*
