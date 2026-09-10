# Decisiones técnicas del backend

Decisiones ya cerradas para el equipo (no rediscutir sin razón explícita) y
propuestas técnicas que siguen en borrador. A diferencia de las notas de
trabajo internas de cada desarrollador, este archivo sí viaja en el repo:
solo debe contener lo que ya es acuerdo de equipo o una propuesta explícita
sobre la que se pide validación, nunca discusiones en curso o notas
personales.

## Roles (cerrado)

`spatie/laravel-permission`, 3 roles fijos — cada usuario tiene **uno solo a
la vez**:

| Rol | Corresponde a (HU-02) |
|---|---|
| `admin` | Administrador |
| `encargado` | Personal de préstamo |
| `usuario` | Solicitante |

Los crea `database/seeders/RoleSeeder.php` de forma idempotente
(`firstOrCreate`), para que existan sin importar el orden en que se corran
los seeders.

## Modelo de datos — alcance funcional (cerrado por el PO, 2026-09-05)

| Tabla / funcionalidad | Decisión | Nota |
|---|---|---|
| `categorias` | Incluir | Agrupa equipos por tipo (portátil, tablet, de mesa...) — HU-03 |
| `unidades_equipo` | No va | Redundante: cada equipo tiene su propio código/serial y se gestiona individual (así quedó implementado en HU-03) |
| `renovaciones` | Incluir | Prorrogar/devolución: editar fecha fin del préstamo |
| `evidencias` | Incluir | Fotos/URLs de condición al entregar y devolver |
| `notificaciones` | No va | Sin uso real; solo alerta de no-devolución en dashboard |
| `mantenimientos` | Diferido | Baja prioridad, último sprint si alcanza |
| `lista_espera` | En espera | Pendiente de consultar con el profesor (cliente) |
| `parametros_sistema` | Opcional | Sin decisión explícita todavía (configuración utilitaria) |
| `historial_estados` | Se mantiene | Trazabilidad/auditoría de cambios de estado |

Reemplaza la propuesta anterior de 12 tablas: esta es la versión acordada.

## Matriz de permisos (propuesta — pendiente de validación del equipo)

⚠️ Todavía no está validada por el equipo. No tratar como definitiva ni
generar `Permission::create(...)` en un seeder a partir de esto sin
confirmar primero con el equipo.

⚠️ **Desactualizada tras la decisión de modelo de datos de arriba**: los
permisos `unidades.*` referencian `unidades_equipo`, que ya no va a existir
(ver tabla anterior). Se dejan tal cual hasta que el equipo la revise, no se
reescriben unilateralmente.

```
equipos.ver, equipos.crear, equipos.editar, equipos.eliminar
unidades.ver, unidades.crear, unidades.editar
prestamos.crear, prestamos.ver_propios, prestamos.ver_todos
prestamos.aprobar, prestamos.rechazar, prestamos.entregar, prestamos.recibir
mantenimientos.gestionar
reportes.ver
parametros.gestionar
```

- `admin`: todos los permisos
- `encargado`: `unidades.*`, `prestamos.ver_todos/aprobar/rechazar/entregar/recibir`, `mantenimientos.gestionar`, `equipos.ver`
- `usuario`: `equipos.ver`, `prestamos.crear`, `prestamos.ver_propios`

## Catálogo de equipos (HU-03)

`equipos` — cada fila es una unidad física identificada por su propio
`codigo` (único). Campos: `codigo`, `nombre`, `categoria_id` (FK a
`categorias`), `descripcion`, `estado`, `observaciones`.

- **`estado`** tiene 3 valores fijos: `disponible` (inicial, lo asigna el
  sistema al registrar — no es un campo de entrada), `en_prestamo`,
  `mantenimiento`. Constantes en `App\Models\Equipo::ESTADOS` /
  `ESTADO_INICIAL`.
- **`categorias`** no tiene CRUD propio todavía — se siembra con
  `CategoriaSeeder` (`Portátil`, `Tablet`, `De mesa`) hasta que exista una HU
  para administrarlas.

Ver `docs/api/equipos.md` para el contrato completo de la API.
