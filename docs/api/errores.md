# Formato estándar de errores de la API

Este documento especifica cómo responde la API ante situaciones de error y define el contrato de códigos de estado HTTP y formatos de mensaje.

La API estandariza todas las respuestas de error bajo el prefijo `/api/*` con un formato JSON predecible, seguro y sin exponer clases internas del framework (`App\Models\...`) ni trazas de depuración al cliente.

---

## 1. Tabla de códigos de estado HTTP y mensajes

| Status | Mensaje retornado (`message`) | Cuándo ocurre | Cabeceras adicionales |
|---|---|---|---|
| **401 Unauthorized** | `"No autenticado."` | Token Sanctum ausente, malformado, vencido o revocado (`AuthenticationException`). | Ninguna |
| **403 Forbidden** | `"No tienes permiso para realizar esta acción."` | Usuario autenticado cuyo rol no tiene autorización para acceder al recurso (`AuthorizationException`, `AccessDeniedHttpException`, rechazo del middleware `role:` de Spatie). | Ninguna |
| **404 Not Found** | `"Recurso no encontrado."` | Modelo no encontrado por Route Model Binding (`ModelNotFoundException`) o endpoint inexistente (`NotFoundHttpException`). Aplica también a equipos dados de baja consultados por rol solicitante (`usuario`). | Ninguna |
| **405 Method Not Allowed** | `"Método no permitido."` | El verbo HTTP empleado (ej. `POST`, `PUT`) no está habilitado para la ruta solicitada (`MethodNotAllowedHttpException`). | `Allow` |
| **422 Unprocessable Content** | *(Resumen de validación en español)* | Falla de validación en los datos de entrada en Form Requests (`ValidationException`). | Ninguna |
| **429 Too Many Requests** | `"Demasiadas solicitudes. Intenta de nuevo más tarde."` | Se excedió el límite de peticiones permitido por el middleware `throttle` (ej. 6 intentos por minuto en `/api/login`). | `Retry-After`, `X-RateLimit-*` |
| **Otros 4xx** (ej. 400, 409, 415) | `"La solicitud no pudo procesarse."` | Errores de cliente HTTP no tipados específicamente en la tabla anterior. | Según aplique |
| **500 Internal Server Error** | `"Error interno del servidor."` | Excepción o fallo no controlado en el servidor, **únicamente cuando `APP_DEBUG=false`**. Preserva el reporte completo en los logs de Laravel. | Ninguna |

---

## 2. Formato de respuestas

### 2.1 Formato general (401, 403, 404, 405, 429, otros 4xx y 500)

Todas las respuestas de error que no sean de validación devuelven un objeto JSON con una única clave `message`:

```json
{
  "message": "Recurso no encontrado."
}
```

### 2.2 Formato de validación (422 Unprocessable Content)

Las peticiones rechazadas por validación devuelven el status `422` con la clave `message` en español y el detalle de errores agrupado por cada campo en la clave `errors`:

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

---

## 3. Principios de integración para el cliente (Frontend)

> [!IMPORTANT]
> **Toma de decisiones por Status HTTP:**
> El cliente debe decidir su flujo y lógica de presentación basándose **estrictamente en el código de status HTTP** y en las claves de `errors` (para el 422), **nunca por el texto literal de `message`**.
>
> Los textos de `message` son informativos y para visualización al usuario final, no para control de flujo en el frontend.

> [!WARNING]
> **Configuración de depuración en entornos compartidos:**
> La respuesta genérica y segura del status `500` (`"Error interno del servidor."`) se activa únicamente cuando `APP_DEBUG=false`.
>
> En entornos de prueba compartidos (staging, QA, demo) y en producción, la variable `APP_DEBUG` debe configurarse **obligatoriamente en `false`** en el archivo `.env` para garantizar que la API nunca exponga credenciales, trazas de base de datos o información técnica interna sensible.
