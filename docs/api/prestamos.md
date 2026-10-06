# Ciclo de préstamos (HU-06, HU-09, HU-11)

Implementa la gestión integral del ciclo de préstamos de equipos universitarios:
- **HU-06:** Solicitud de préstamo con motivo, fechas y verificación de disponibilidad.
- **HU-09:** Registro de entrega del equipo por personal autorizado.
- **HU-11:** Registro de devolución del equipo y actualización de condición física/estado.

---

## 1. Resumen de Endpoints

| Método | Endpoint | Rol requerido | Propósito |
|---|---|---|---|
| `GET` | `/api/prestamos` | Cualquier autenticado | Listado paginado de préstamos (usuario común ve solo los suyos; admin y encargado ven todos). |
| `POST` | `/api/prestamos` | Cualquier autenticado | Registra una nueva solicitud de préstamo en estado `solicitado`. |
| `GET` | `/api/prestamos/activos` | `admin`, `encargado` | Listado paginado de préstamos activos (`entregado`) con búsqueda textual. |
| `GET` | `/api/prestamos/{prestamo}` | Propietario / `admin` / `encargado` | Consulta el detalle puntual de un préstamo. |
| `POST` | `/api/prestamos/{prestamo}/entrega` | `admin`, `encargado` | Registra la entrega del equipo (`aprobado` -> `entregado`). |
| `POST` | `/api/prestamos/{prestamo}/devolucion` | `admin`, `encargado` | Registra la devolución del equipo (`entregado` -> `devuelto`). |

### Cabeceras comunes
```http
Authorization: Bearer <token_sanctum>
Accept: application/json
Content-Type: application/json
```

---

## 2. Solicitud de Préstamo (`POST /api/prestamos` — HU-06)

Permite a cualquier usuario autenticado solicitar un equipo que se encuentre disponible.

### Payload de la petición
| Campo | Tipo | Obligatorio | Reglas de validación |
|---|---|---|---|
| `equipo_id` | entero | Sí | Debe existir en la tabla `equipos`. |
| `motivo` | string | Sí | Máx. 1000 caracteres. No puede estar vacío ni contener solo espacios. |
| `fecha_inicio` | datetime | Sí | Fecha con hora válida. Debe ser igual o posterior al momento actual. |
| `fecha_devolucion_estimada` | datetime | Sí | Fecha con hora válida, posterior a `fecha_inicio`, sin superar los días máximos configurados (`config('prestamos.duracion_maxima_dias')`, por defecto 7 días). |

> [!NOTE]
> Los campos `usuario_id`, `estado` y `fecha_solicitud` son asignados exclusivamente por el servidor (`usuario_id = auth()->id()`, `estado = 'solicitado'`, `fecha_solicitud = now()`). Cualquier valor enviado por el cliente es ignorado.

### Formatos de fecha y manejo de zona horaria
La aplicación y la base de datos operan internamente en **UTC**. La zona horaria de negocio de la universidad es **Colombia (`America/Bogota`, UTC-5)**, configurada en `config('prestamos.zona_horaria')`.

**Formatos de entrada aceptados:**
1. `"Y-m-d H:i"` y `"Y-m-d H:i:s"` (sin desplazamiento: se interpretan en hora de Colombia).
2. `"Y-m-d\TH:i"` y `"Y-m-d\TH:i:s"` (sin desplazamiento: se interpretan en hora de Colombia).
3. ISO 8601 con desplazamiento o Z (con o sin segundos, con o sin fracción de segundos, ej. `"2026-10-15T08:00-05:00"` y `"2026-10-15T08:00Z"`).

Cualquier otro formato se rechaza estrictamente con **HTTP 422**:
- Fechas sin hora (ej. `"2026-10-15"`).
- Cadenas relativas (ej. `"tomorrow"`).
- Formatos con barras (ej. `"15/10/2026 08:00"`).

> [!IMPORTANT]
> **Formato de fechas en respuestas:**
> - Todas las fechas y timestamps del recurso del préstamo (`fecha_solicitud`, `fecha_inicio`, `fecha_devolucion_estimada`, `fecha_aprobacion`, `fecha_entrega_real`, `fecha_devolucion_real`, `created_at`, `updated_at`) se devuelven en formato ISO 8601 con el desplazamiento de Colombia (`-05:00`), por ejemplo `2026-10-10T08:00:00-05:00`.
> - Los recursos del catálogo y timestamps estándar de otras entidades (como `HistorialEstadoResource`) devuelven formato ISO 8601 en UTC con sufijo `Z` (ej. `2026-10-05T14:30:00.000000Z`).
> - Ambos son instantes ISO 8601 válidos y estándar. El cliente / frontend debe parsearlos utilizando tipos y librerías de fecha estándar (ej. `new Date(cadena)` en JavaScript) y **nunca** asumiendo posiciones de caracteres o formatos fijos.

### Ejemplo de petición
```http
POST /api/prestamos HTTP/1.1
Content-Type: application/json

{
  "equipo_id": 12,
  "motivo": "Práctica de laboratorio para la asignatura de Redes y Conectividad.",
  "fecha_inicio": "2026-10-10 08:00:00",
  "fecha_devolucion_estimada": "2026-10-13 18:00:00"
}
```

### Respuesta exitosa (`201 Created`)
```json
{
  "data": {
    "id": 1,
    "usuario_id": 4,
    "solicitante": {
      "id": 4,
      "name": "Carlos Mendoza"
    },
    "equipo_id": 12,
    "equipo": {
      "id": 12,
      "codigo": "EQ-PORT-012",
      "nombre": "Portátil Dell Latitude 3420",
      "categoria": {
        "id": 1,
        "nombre": "Portátil"
      },
      "descripcion": "Intel Core i5, 16GB RAM",
      "disponible": true,
      "puede_solicitarse": true,
      "estado_disponibilidad": "disponible",
      "estado": "disponible"
    },
    "estado": "solicitado",
    "estado_etiqueta": "Solicitado",
    "motivo": "Práctica de laboratorio para la asignatura de Redes y Conectividad.",
    "fecha_solicitud": "2026-10-05T09:30:00-05:00",
    "fecha_inicio": "2026-10-10T08:00:00-05:00",
    "fecha_devolucion_estimada": "2026-10-13T18:00:00-05:00",
    "fecha_aprobacion": null,
    "fecha_entrega_real": null,
    "fecha_devolucion_real": null,
    "condicion_entrega": null,
    "condicion_entrega_etiqueta": null,
    "condicion_devolucion": null,
    "condicion_devolucion_etiqueta": null,
    "entregado_por": null,
    "usuario_entrega": null,
    "recibido_por": null,
    "usuario_recepcion": null,
    "created_at": "2026-10-05T09:30:00-05:00",
    "updated_at": "2026-10-05T09:30:00-05:00"
  }
}
```

---

## 3. Listado de Préstamos (`GET /api/prestamos`)

Lista paginada de préstamos:
- **`usuario`**: Filtra automáticamente para retornar únicamente sus propios préstamos.
- **`admin` y `encargado`**: Retorna todos los préstamos registrados.

### Parámetros opcionales
- `per_page`: Entero de 1 a 100 (por defecto 15).

---

## 4. Detalle de Préstamo (`GET /api/prestamos/{prestamo}`)

Consulta el detalle puntual de un préstamo.
- **Acceso:** El solicitante dueño del préstamo, administradores y encargados.
- **Visibilidad y confidencialidad:** Si un usuario común intenta consultar un préstamo que no le pertenece, la API responde `404 Not Found` (`{"message": "Recurso no encontrado."}`) con el mismo cuerpo que si el ID no existiera, evitando revelar la existencia del registro (mismo criterio de `EquipoController`).
- **Privacidad de datos de usuarios anidados y observaciones:**
  - `usuario_entrega` y `usuario_recepcion`: Siempre devuelven únicamente `{ "id": int, "name": string }` (mismo formato mínimo de `HistorialEstadoResource`).
  - `solicitante`: Para rol `usuario` devuelve solo `{ "id": int, "name": string }`. Para roles administrativos (`admin`, `encargado`) incluye además `{ "email": string, "is_active": bool, "roles": [...] }`.
  - `observaciones_entrega` y `observaciones_devolucion`: Visibles exclusivamente para roles administrativos (`admin`, `encargado`). Los usuarios solicitantes no reciben ninguna de las dos claves en la respuesta. Las condiciones físicas (`condicion_entrega` y `condicion_devolucion`) con sus etiquetas sí permanecen visibles para el solicitante.

#### Ejemplo para rol `usuario`:
```json
{
  "data": {
    "id": 1,
    "usuario_id": 4,
    "solicitante": {
      "id": 4,
      "name": "Prueba Usuario"
    },
    "equipo_id": 1,
    "equipo": {
      "id": 1,
      "codigo": "PRUEBA-001",
      "nombre": "Portátil de prueba",
      "categoria": { "id": 1, "nombre": "Pruebas" },
      "descripcion": null,
      "disponible": false,
      "puede_solicitarse": false,
      "estado_disponibilidad": "no_disponible",
      "estado": "mantenimiento"
    },
    "estado": "devuelto",
    "estado_etiqueta": "Devuelto",
    "motivo": "Practica de laboratorio",
    "fecha_solicitud": "2026-10-05T14:58:38-05:00",
    "fecha_inicio": "2026-10-06T08:00:00-05:00",
    "fecha_devolucion_estimada": "2026-10-07T17:00:00-05:00",
    "fecha_aprobacion": "2026-10-05T14:59:18-05:00",
    "fecha_entrega_real": "2026-10-05T14:59:32-05:00",
    "fecha_devolucion_real": "2026-10-05T14:59:40-05:00",
    "condicion_entrega": "bueno",
    "condicion_entrega_etiqueta": "Bueno",
    "condicion_devolucion": "con_danos",
    "condicion_devolucion_etiqueta": "Con daños",
    "entregado_por": 5,
    "usuario_entrega": {
      "id": 5,
      "name": "Prueba Encargado"
    },
    "recibido_por": 5,
    "usuario_recepcion": {
      "id": 5,
      "name": "Prueba Encargado"
    },
    "created_at": "2026-10-05T14:58:38-05:00",
    "updated_at": "2026-10-05T14:59:40-05:00"
  }
}
```

#### Ejemplo para rol `encargado` o `admin`:
```json
{
  "data": {
    "id": 1,
    "usuario_id": 4,
    "solicitante": {
      "id": 4,
      "name": "Prueba Usuario",
      "email": "prueba.usuario@test.com",
      "is_active": true,
      "roles": ["usuario"]
    },
    "equipo_id": 1,
    "equipo": {
      "id": 1,
      "codigo": "PRUEBA-001",
      "nombre": "Portátil de prueba",
      "categoria": { "id": 1, "nombre": "Pruebas" },
      "descripcion": null,
      "disponible": false,
      "puede_solicitarse": false,
      "estado_disponibilidad": "no_disponible",
      "estado": "mantenimiento",
      "observaciones": null
    },
    "estado": "devuelto",
    "estado_etiqueta": "Devuelto",
    "motivo": "Practica de laboratorio",
    "fecha_solicitud": "2026-10-05T14:58:38-05:00",
    "fecha_inicio": "2026-10-06T08:00:00-05:00",
    "fecha_devolucion_estimada": "2026-10-07T17:00:00-05:00",
    "fecha_aprobacion": "2026-10-05T14:59:18-05:00",
    "fecha_entrega_real": "2026-10-05T14:59:32-05:00",
    "fecha_devolucion_real": "2026-10-05T14:59:40-05:00",
    "condicion_entrega": "bueno",
    "condicion_entrega_etiqueta": "Bueno",
    "condicion_devolucion": "con_danos",
    "condicion_devolucion_etiqueta": "Con daños",
    "entregado_por": 5,
    "usuario_entrega": {
      "id": 5,
      "name": "Prueba Encargado"
    },
    "recibido_por": 5,
    "usuario_recepcion": {
      "id": 5,
      "name": "Prueba Encargado"
    },
    "observaciones_entrega": "Se entrega con cargador",
    "observaciones_devolucion": "Golpe en la esquina",
    "created_at": "2026-10-05T14:58:38-05:00",
    "updated_at": "2026-10-05T14:59:40-05:00"
  }
}
```

---

## 5. Préstamos Activos (`GET /api/prestamos/activos` — HU-11)

Exclusivo para roles `admin` y `encargado`. Devuelve préstamos actualmente en posesión del solicitante (`estado = 'entregado'`).

### Parámetros opcionales
| Parámetro | Tipo | Descripción |
|---|---|---|
| `buscar` | string | Búsqueda parcial sobre código de equipo, nombre de equipo, nombre del solicitante o correo del solicitante. Caracteres `%` y `_` escapados automáticamente. |
| `per_page` | entero | Entero de 1 a 100 (por defecto 15). |

---

## 6. Registro de Entrega (`POST /api/prestamos/{prestamo}/entrega` — HU-09)

Exclusivo para roles `admin` y `encargado`. Registra que el equipo físico ha sido entregado al solicitante.

### Reglas de negocio
- El préstamo debe estar en estado `aprobado` (si no, retorna `422`).
- El equipo debe encontrarse disponible físicamente.
- `fecha_entrega_real` no puede ser posterior al momento actual (con tolerancia de 1 minuto) ni anterior a la fecha de aprobación del préstamo (`fecha_aprobacion`).
- El préstamo pasa a `entregado`, se almacena `fecha_entrega_real`, `entregado_por` (usuario autenticado), `condicion_entrega` y se guarda la observación en `observaciones_entrega`.
- El equipo cambia de estado a `en_prestamo` (registrado automáticamente en `historial_estados`).

### Payload de la petición
| Campo | Tipo | Obligatorio | Valores / Descripción |
|---|---|---|---|
| `fecha_entrega_real` | datetime | No | Fecha/hora real de entrega. Por defecto `now()`. Mismos formatos aceptados que en solicitud; sin desplazamiento se interpreta en hora de Colombia. No puede ser futura ni anterior a `fecha_aprobacion`. |
| `condicion_entrega` | string | No | `bueno`, `con_danos`, `requiere_mantenimiento` (por defecto `bueno`). |
| `observaciones` | string | No | Observaciones sobre la condición de entrega (máx. 2000 caracteres). Se almacena en la columna `observaciones_entrega`. |

### Ejemplo de petición
```json
{
  "condicion_entrega": "bueno",
  "observaciones": "Equipo entregado con adaptador de corriente y funda protectora."
}
```

---

## 7. Registro de Devolución (`POST /api/prestamos/{prestamo}/devolucion` — HU-11)

Exclusivo para roles `admin` y `encargado`. Registra la recepción física del equipo devuelto.

### Reglas de negocio
- El préstamo debe encontrarse en estado `entregado` (si no, retorna `422`).
- `fecha_devolucion_real` no puede ser posterior al momento actual (con tolerancia de 1 minuto) ni anterior a la fecha real de entrega (`fecha_entrega_real`).
- `condicion_devolucion` es obligatoria (`bueno`, `con_danos`, `requiere_mantenimiento`).
- Si la condición es distinta de `bueno`, el campo `observaciones` es **obligatorio**.
- El préstamo pasa a `devuelto`, se almacena `fecha_devolucion_real`, `recibido_por` y se almacena la observación en `observaciones_devolucion`, conservando intactas las observaciones previas de la entrega (`observaciones_entrega`).
- Si la condición es `bueno`, el equipo regresa a `disponible`. En cualquier otro caso, pasa a `mantenimiento` (registrado en `historial_estados`).

### Payload de la petición
| Campo | Tipo | Obligatorio | Valores / Descripción |
|---|---|---|---|
| `fecha_devolucion_real` | datetime | No | Fecha/hora real de devolución. Por defecto `now()`. Mismos formatos aceptados; sin desplazamiento se interpreta en hora de Colombia. No puede ser futura ni anterior a `fecha_entrega_real`. |
| `condicion_devolucion` | string | Sí | `bueno`, `con_danos`, `requiere_mantenimiento`. |
| `observaciones` | string | Condicional | Obligatorio si la condición es distinta de `bueno` (máx. 2000 caracteres). |

### Ejemplo de petición
```json
{
  "condicion_devolucion": "con_danos",
  "observaciones": "Pantalla presenta fisura en la esquina superior derecha."
}
```
