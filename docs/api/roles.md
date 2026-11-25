# Roles y cupos de préstamo (RN-02)

Todos los endpoints requieren estar autenticado (Sanctum) **y** tener el rol
`admin`. Permiten crear roles personalizados para los solicitantes
(estudiante, profesor, coordinador…) y definir el **cupo de préstamos vivos**
de cada uno.

## Tipos de rol

| Tipo | Roles | Qué permiten |
|---|---|---|
| **Sistema** | `admin`, `encargado` | Permisos de personal (fijados en las rutas). Se puede cambiar su etiqueta, descripción y cupo; **no** se pueden eliminar ni ser predeterminados. |
| **Solicitante** | `estudiante` (inicial) y los que cree el admin | Solo solicitar préstamos. Cada uno tiene su cupo y sus días extra de duración (RN-06, `duracion.roles_extra`). |

- El rol **predeterminado** (por defecto `estudiante`) es el que recibe una
  cuenta creada por `POST /api/register`. Siempre hay exactamente uno.
- Un préstamo **vivo** está en `solicitado`, `aprobado` o `entregado`. Con
  cupo `0` el rol no puede solicitar.
- Si el rol de un usuario ya no existe, se aplica el cupo del predeterminado.

## Endpoints

| Método | Endpoint | Descripción |
|---|---|---|
| `GET` | `/api/roles` | Lista todos los roles con su cupo y cuántos usuarios tienen |
| `POST` | `/api/roles` | Crea un rol de solicitante |
| `GET` | `/api/roles/{rol}` | Consulta un rol |
| `PUT` / `PATCH` | `/api/roles/{rol}` | Cambia etiqueta, descripción, cupo y/o la marca de predeterminado |
| `DELETE` | `/api/roles/{rol}` | Elimina un rol de solicitante sin usuarios (`204`) |

## Campos

| Campo | Tipo | Reglas |
|---|---|---|
| `etiqueta` | texto | Obligatorio al crear, 3–60 caracteres, único sin distinguir mayúsculas. De ella se deriva `nombre` |
| `nombre` | texto (solo lectura) | Nombre técnico: `Coordinador académico` → `coordinador_academico`. No cambia al editar la etiqueta |
| `descripcion` | texto \| null | Opcional, máx. 255 |
| `cupo_prestamos` | entero | Obligatorio al crear, `0`–`1000` |
| `es_predeterminado` | booleano | Marcar uno desmarca el anterior. No se puede desmarcar el actual (se marca otro) ni marcar un rol de sistema |
| `es_sistema` | booleano (solo lectura) | `true` para `admin` y `encargado` |
| `usuarios_count` | entero (solo lectura) | Usuarios con ese rol |

## Errores

| Caso | Código |
|---|---|
| Sin token / sin rol `admin` | `401` / `403` |
| Etiqueta repetida, reservada (`admin`, `encargado`, `usuario`) o sin letras ni números | `422` |
| Cupo negativo, no entero o mayor que 1000 | `422` |
| Desmarcar el predeterminado o marcar un rol de sistema | `422` |
| Eliminar un rol de sistema, el predeterminado o uno con usuarios | `422` |

## Relación con otros endpoints

- `POST/PUT /api/usuarios` acepta en `role` cualquier rol existente.
- `GET /api/configuracion` incluye `data.roles` y sigue exponiendo
  `data.cupos.roles` (mapa `nombre → cupo`); `PUT /api/configuracion` con
  `cupos.roles` actualiza los cupos de roles **existentes** (un rol
  desconocido es `422`). La fuente de verdad es la tabla `roles`.
- `UserResource` incluye `rol_principal` y `rol_etiqueta`.

## Ejemplos

**Crear el rol Profesor con cupo 5**

```sh
curl -i -X POST http://localhost:8000/api/roles \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"etiqueta": "Profesor", "cupo_prestamos": 5}'
```

Respuesta (`201`):

```json
{
  "data": {
    "id": 4,
    "nombre": "profesor",
    "etiqueta": "Profesor",
    "descripcion": null,
    "cupo_prestamos": 5,
    "es_sistema": false,
    "es_predeterminado": false,
    "usuarios_count": 0
  }
}
```

**Cambiar el cupo del estudiante**

```sh
curl -i -X PATCH http://localhost:8000/api/roles/3 \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"cupo_prestamos": 2}'
```

**Asignar el rol a un usuario**

```sh
curl -i -X PATCH http://localhost:8000/api/usuarios/7 \
  -H "Authorization: Bearer <token-admin>" \
  -H "Content-Type: application/json" \
  -d '{"role": "profesor"}'
```
