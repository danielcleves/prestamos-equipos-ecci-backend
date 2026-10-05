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
3. ISO 8601 con desplazamiento o Z (`"Y-m-d\TH:i:sP"`, con o sin fracción de segundos).

Cualquier otro formato se rechaza estrictamente con **HTTP 422**:
- Fechas sin hora (ej. `"2026-10-15"`).
- Cadenas relativas (ej. `"tomorrow"`).
- Formatos con barras (ej. `"15/10/2026 08:00"`).

> [!IMPORTANT]
> **Formato de fechas en respuestas:**
> - Las seis fechas específicas del préstamo (`fecha_solicitud`, `fecha_inicio`, `fecha_devolucion_estimada`, `fecha_aprobacion`, `fecha_entrega_real`, `fecha_devolucion_real`) se devuelven en formato ISO 8601 con el desplazamiento de Colombia (`-05:00`), por ejemplo `2026-10-10T08:00:00-05:00`.
> - Los recursos del catálogo y timestamps estándar del framework (como `HistorialEstadoResource`, `created_at`, `updated_at`) devuelven formato ISO 8601 en UTC con sufijo `Z` (ej. `2026-10-05T14:30:00.000000Z`).
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
      "name": "Carlos Mendoza",
      "email": "carlos.mendoza@ecci.edu.co",
      "is_active": true,
      "roles": ["usuario"]
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
    "observaciones": null,
    "created_at": "2026-10-05T14:30:00.000000Z",
    "updated_at": "2026-10-05T14:30:00.000000Z"
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

## 4. Préstamos Activos (`GET /api/prestamos/activos` — HU-11)

Exclusivo para roles `admin` y `encargado`. Devuelve préstamos actualmente en posesión del solicitante (`estado = 'entregado'`).

### Parámetros opcionales
| Parámetro | Tipo | Descripción |
|---|---|---|
| `buscar` | string | Búsqueda parcial sobre código de equipo, nombre de equipo, nombre del solicitante o correo del solicitante. Caracteres `%` y `_` escapados automáticamente. |
| `per_page` | entero | Entero de 1 a 100 (por defecto 15). |

---

## 5. Registro de Entrega (`POST /api/prestamos/{prestamo}/entrega` — HU-09)

Exclusivo para roles `admin` y `encargado`. Registra que el equipo físico ha sido entregado al solicitante.

### Reglas de negocio
- El préstamo debe estar en estado `aprobado` (si no, retorna `422`).
- El equipo debe encontrarse disponible físicamente.
- `fecha_entrega_real` no puede ser posterior al momento actual (con tolerancia de 1 minuto) ni anterior a la fecha de aprobación del préstamo (`fecha_aprobacion`).
- El préstamo pasa a `entregado`, se almacena `fecha_entrega_real`, `entregado_por` (usuario autenticado), `condicion_entrega` y se anexa la observación con prefijo `"Entrega: "`.
- El equipo cambia de estado a `en_prestamo` (registrado automáticamente en `historial_estados`).

### Payload de la petición
| Campo | Tipo | Obligatorio | Valores / Descripción |
|---|---|---|---|
| `fecha_entrega_real` | datetime | No | Fecha/hora real de entrega. Por defecto `now()`. Mismos formatos aceptados que en solicitud; sin desplazamiento se interpreta en hora de Colombia. No puede ser futura ni anterior a `fecha_aprobacion`. |
| `condicion_entrega` | string | No | `bueno`, `con_danos`, `requiere_mantenimiento` (por defecto `bueno`). |
| `observaciones` | string | No | Observaciones sobre la condición de entrega (máx. 2000 caracteres). |

### Ejemplo de petición
```json
{
  "condicion_entrega": "bueno",
  "observaciones": "Equipo entregado con adaptador de corriente y funda protectora."
}
```

---

## 6. Registro de Devolución (`POST /api/prestamos/{prestamo}/devolucion` — HU-11)

Exclusivo para roles `admin` y `encargado`. Registra la recepción física del equipo devuelto.

### Reglas de negocio
- El préstamo debe encontrarse en estado `entregado` (si no, retorna `422`).
- `fecha_devolucion_real` no puede ser posterior al momento actual (con tolerancia de 1 minuto) ni anterior a la fecha real de entrega (`fecha_entrega_real`).
- `condicion_devolucion` es obligatoria (`bueno`, `con_danos`, `requiere_mantenimiento`).
- Si la condición es distinta de `bueno`, el campo `observaciones` es **obligatorio**.
- El préstamo pasa a `devuelto`, se almacena `fecha_devolucion_real`, `recibido_por` y se concatena la observación con prefijo `"Devolución: "` en nueva línea sin sobrescribir las observaciones previas de la entrega.
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
