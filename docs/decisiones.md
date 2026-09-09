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

## Matriz de permisos (propuesta — pendiente de validación del equipo)

⚠️ Todavía no está validada por el equipo. Puede cambiar si el modelo de
datos de negocio (equipos/préstamos) se recorta — por ejemplo, los permisos
sobre `mantenimientos` no aplicarían si esa tabla no llega a existir. No
tratar como definitiva ni generar `Permission::create(...)` en un seeder a
partir de esto sin confirmar primero con el equipo.

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
