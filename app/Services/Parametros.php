<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Rol;

/**
 * Parámetros de negocio de la iteración 2 (RN-01, RN-02, RN-04, RN-06, RN-08).
 *
 * Los valores viven en la tabla 'configuraciones' y son administrables por el
 * admin. Cuando una clave no está guardada se usa el valor por defecto del
 * contrato de diseño, de modo que la aplicación funciona igual con la tabla
 * vacía (por ejemplo, en pruebas o en una instalación recién migrada).
 *
 * Excepción: el cupo por rol (RN-02) no vive en 'configuraciones' sino en la
 * tabla 'roles' (columna cupo_prestamos), porque el admin crea roles
 * personalizados. Se sigue exponiendo como la clave virtual 'cupos.roles' para
 * que GET/PUT /api/configuracion mantengan su contrato.
 *
 * La instancia cachea los valores en memoria: el contenedor crea una por
 * servicio que la inyecta, así que dentro de una misma operación no se repite
 * la consulta.
 */
class Parametros
{
    /**
     * Definición de cada parámetro: tipo, grupo, descripción y valor inicial.
     *
     * Es también la lista blanca de claves que acepta PUT /api/configuracion.
     *
     * @var array<string, array{tipo: string, grupo: string, descripcion: string, default: mixed}>
     */
    /** Clave virtual del cupo por rol: se lee y escribe en la tabla 'roles'. */
    public const CLAVE_CUPOS = 'cupos.roles';

    public const DEFINICIONES = [
        'duracion.categorias' => [
            'tipo' => 'json',
            'grupo' => 'duracion',
            'descripcion' => 'Días máximos de préstamo por categoría de equipo (por id).',
            'default' => ['1' => 7, '2' => 5, '3' => 3],
        ],
        'duracion.roles_extra' => [
            'tipo' => 'json',
            'grupo' => 'duracion',
            'descripcion' => 'Días adicionales de préstamo según el rol del solicitante.',
            'default' => ['estudiante' => 0, 'encargado' => 2, 'admin' => 3],
        ],
        'mora.escala' => [
            'tipo' => 'json',
            'grupo' => 'mora',
            'descripcion' => 'Escala de sanción: días de retraso desde/hasta y días de sanción.',
            'default' => [
                ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 3],
                ['desde' => 3, 'hasta' => 5, 'dias_sancion' => 7],
                ['desde' => 6, 'hasta' => null, 'dias_sancion' => 15],
            ],
        ],
        'mora.dias_bloqueo' => [
            'tipo' => 'int',
            'grupo' => 'mora',
            'descripcion' => 'Días de retraso de un préstamo entregado que bloquean nuevas solicitudes.',
            'default' => 1,
        ],
        'danos.dias_restriccion' => [
            'tipo' => 'int',
            'grupo' => 'danos',
            'descripcion' => 'Días que el solicitante queda restringido al devolver un equipo con daños.',
            'default' => 7,
        ],
        'elegibilidad.requiere_capacitacion' => [
            'tipo' => 'bool',
            'grupo' => 'elegibilidad',
            'descripcion' => 'Exige estar capacitado para solicitar préstamos.',
            'default' => false,
        ],
        'registro.correos_permitidos' => [
            'tipo' => 'json',
            'grupo' => 'registro',
            'descripcion' => 'Correos exactos o dominios (@dominio.edu.co) autorizados para el auto-registro de solicitantes.',
            'default' => ['@ecci.edu.co'],
        ],
    ];

    /**
     * Valores efectivos ya resueltos (defaults + overrides de la base).
     *
     * @var array<string, mixed>|null
     */
    private ?array $valores = null;

    /**
     * Codifica un valor para guardarlo en la columna de texto 'valor'.
     */
    public static function codificar(mixed $valor, string $tipo): string
    {
        return match ($tipo) {
            'json' => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null',
            'bool' => filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            default => (string) (int) $valor,
        };
    }

    /**
     * Decodifica un valor crudo según su tipo, con el default como respaldo.
     */
    public static function decodificar(?string $valor, string $tipo, mixed $default): mixed
    {
        if ($valor === null || trim($valor) === '') {
            return $default;
        }

        return match ($tipo) {
            'json' => json_decode($valor, true) ?? $default,
            'bool' => filter_var($valor, FILTER_VALIDATE_BOOLEAN),
            default => is_numeric($valor) ? (int) $valor : $default,
        };
    }

    /**
     * Cupo de préstamos vivos para el rol indicado (RN-02).
     */
    public function cupoActivo(string $rol): int
    {
        $cupos = $this->valores()[self::CLAVE_CUPOS];

        if (isset($cupos[$rol])) {
            return (int) $cupos[$rol];
        }

        // Rol desconocido (p. ej. borrado mientras había una sesión abierta):
        // se aplica el cupo del rol predeterminado.
        $predeterminado = Rol::predeterminado();

        return $predeterminado !== null
            ? (int) $predeterminado->cupo_prestamos
            : Rol::CUPO_RESPALDO;
    }

    /**
     * Duración máxima en días: días de la categoría + extra del rol (RN-06).
     * Si la categoría no está configurada se asume el máximo histórico de 7.
     */
    public function duracionMaxima(int $categoriaId, string $rol): int
    {
        $porCategoria = $this->valores()['duracion.categorias'];
        $porRol = $this->valores()['duracion.roles_extra'];

        $base = (int) ($porCategoria[(string) $categoriaId] ?? $porCategoria[$categoriaId] ?? 7);
        $extra = (int) ($porRol[$rol] ?? 0);

        return max(0, $base + $extra);
    }

    /**
     * Escala de mora ordenada por 'desde' (RN-04).
     *
     * @return array<int, array{desde: int, hasta: int|null, dias_sancion: int}>
     */
    public function escalaMora(): array
    {
        $escala = $this->valores()['mora.escala'];

        usort($escala, fn (array $a, array $b) => ($a['desde'] ?? 0) <=> ($b['desde'] ?? 0));

        return $escala;
    }

    /**
     * Días de sanción que corresponden a un retraso dado, o null si ningún
     * tramo lo cubre.
     */
    public function diasSancionPorRetraso(int $diasRetraso): ?int
    {
        foreach ($this->escalaMora() as $tramo) {
            $desde = (int) ($tramo['desde'] ?? 0);
            $hasta = $tramo['hasta'] ?? null;

            if ($diasRetraso >= $desde && ($hasta === null || $diasRetraso <= (int) $hasta)) {
                return (int) ($tramo['dias_sancion'] ?? 0);
            }
        }

        return null;
    }

    /**
     * Días de retraso que bloquean nuevas solicitudes (RN-04).
     */
    public function diasBloqueoMora(): int
    {
        return (int) $this->valores()['mora.dias_bloqueo'];
    }

    /**
     * Días de restricción al devolver un equipo con daños (RN-08).
     */
    public function diasRestriccionDanos(): int
    {
        return (int) $this->valores()['danos.dias_restriccion'];
    }

    /**
     * Indica si se exige capacitación para solicitar (RN-01).
     */
    public function requiereCapacitacion(): bool
    {
        return (bool) $this->valores()['elegibilidad.requiere_capacitacion'];
    }

    /**
     * Correos exactos o dominios autorizados para el auto-registro.
     *
     * @return list<string>
     */
    public function correosPermitidos(): array
    {
        $valor = $this->valores()['registro.correos_permitidos'] ?? [];

        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter(
            $valor,
            fn ($item) => is_string($item) && trim($item) !== ''
        ));
    }

    /**
     * Valores efectivos indexados por clave.
     *
     * @return array<string, mixed>
     */
    public function valores(): array
    {
        if ($this->valores !== null) {
            return $this->valores;
        }

        $valores = [];
        foreach (self::DEFINICIONES as $clave => $definicion) {
            $valores[$clave] = $definicion['default'];
        }

        $valores[self::CLAVE_CUPOS] = Rol::mapaCupos()->all();

        foreach (Configuracion::query()->get(['clave', 'tipo', 'valor']) as $fila) {
            if (! isset(self::DEFINICIONES[$fila->clave])) {
                continue;
            }

            $valores[$fila->clave] = self::decodificar(
                $fila->valor,
                $fila->tipo,
                self::DEFINICIONES[$fila->clave]['default']
            );
        }

        return $this->valores = $valores;
    }

    /**
     * Valores agrupados por 'grupo' para GET /api/configuracion.
     * La clave 'mora.escala' se expone como grupo 'mora' y campo 'escala'.
     *
     * @return array<string, array<string, mixed>>
     */
    public function porGrupos(): array
    {
        $agrupados = [];

        foreach ($this->valores() as $clave => $valor) {
            [$grupo, $campo] = explode('.', $clave, 2);
            $agrupados[$grupo][$campo] = $valor;
        }

        return $agrupados;
    }
}
