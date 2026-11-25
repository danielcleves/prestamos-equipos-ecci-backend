<?php

namespace App\Http\Requests\Api;

use App\Models\Categoria;
use App\Models\Rol;
use App\Services\Parametros;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/configuracion.
 *
 * Acepta el mapa plano «clave => valor» descrito en el contrato o envuelto en
 * «parametros». Solo se admiten claves conocidas y valores coherentes con su
 * tipo.
 */
class UpdateConfiguracionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorización (solo admin) la resuelve el middleware 'role:admin'.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parametros' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $mapa = $this->parametros();

            if ($mapa === []) {
                $validator->errors()->add('parametros', 'Debes enviar al menos un parámetro.');

                return;
            }

            foreach ($mapa as $clave => $valor) {
                if ($clave === Parametros::CLAVE_CUPOS) {
                    foreach ($this->erroresCupos($valor) as $error) {
                        $validator->errors()->add("parametros.{$clave}", $error);
                    }

                    continue;
                }

                $definicion = Parametros::DEFINICIONES[$clave] ?? null;

                if ($definicion === null) {
                    $validator->errors()->add("parametros.{$clave}", "El parámetro «{$clave}» no existe.");

                    continue;
                }

                foreach ($this->erroresDeTipo($clave, $valor, $definicion['tipo']) as $error) {
                    $validator->errors()->add("parametros.{$clave}", $error);
                }
            }
        });
    }

    /**
     * Mapa de parámetros recibido: envuelto en 'parametros' o el cuerpo plano.
     *
     * @return array<string, mixed>
     */
    public function parametros(): array
    {
        $parametros = $this->input('parametros');

        if (is_array($parametros) && $parametros !== []) {
            return $parametros;
        }

        return $this->except(['parametros']);
    }

    /**
     * @return array<int, string>
     */
    private function erroresDeTipo(string $clave, mixed $valor, string $tipo): array
    {
        if ($tipo === 'int') {
            if (! $this->esEnteroNoNegativo($valor)) {
                return ["El parámetro «{$clave}» debe ser un entero mayor o igual a cero."];
            }

            return [];
        }

        if ($tipo === 'bool') {
            if (! is_bool($valor) && ! in_array($valor, [0, 1, '0', '1', 'true', 'false'], true)) {
                return ["El parámetro «{$clave}» debe ser verdadero o falso."];
            }

            return [];
        }

        if (! is_array($valor)) {
            return ["El parámetro «{$clave}» debe ser un objeto o lista."];
        }

        if ($clave === 'mora.escala') {
            return $this->erroresEscalaMora($valor);
        }

        if ($clave === 'registro.correos_permitidos') {
            return $this->erroresCorreosPermitidos($valor);
        }

        if ($clave === 'duracion.categorias') {
            return $this->erroresDuracionCategorias($valor);
        }

        if ($clave === 'duracion.roles_extra') {
            $existentes = Rol::query()->pluck('name')->all();

            foreach (array_keys($valor) as $nombre) {
                if (! in_array((string) $nombre, $existentes, true)) {
                    return ["El rol «{$nombre}» no existe. Créalo primero en la gestión de roles."];
                }
            }
        }

        // duracion.roles_extra es un mapa «rol => días extra» (0 es válido).
        foreach ($valor as $nombre => $numero) {
            if (! $this->esEnteroNoNegativo($numero)) {
                return ["El valor de «{$nombre}» en «{$clave}» debe ser un entero mayor o igual a cero."];
            }
        }

        return [];
    }

    /**
     * cupos.roles: mapa «nombre de rol => cupo». El rol debe existir; los roles
     * nuevos se crean en /api/roles.
     *
     * @return array<int, string>
     */
    private function erroresCupos(mixed $valor): array
    {
        if (! is_array($valor) || $valor === []) {
            return ['El parámetro «cupos.roles» debe ser un objeto «rol => cupo».'];
        }

        $existentes = Rol::query()->pluck('name')->all();

        foreach ($valor as $nombre => $numero) {
            if (! in_array((string) $nombre, $existentes, true)) {
                return ["El rol «{$nombre}» no existe. Créalo primero en la gestión de roles."];
            }

            if (! $this->esEnteroNoNegativo($numero) || (int) $numero > Rol::CUPO_MAXIMO) {
                return ["El cupo de «{$nombre}» debe ser un entero entre 0 y ".Rol::CUPO_MAXIMO.'.'];
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $correos
     * @return array<int, string>
     */
    private function erroresCorreosPermitidos(array $correos): array
    {
        if ($correos === []) {
            return ['Debe autorizar al menos un correo o dominio para el registro.'];
        }

        foreach ($correos as $correo) {
            if (! is_string($correo) || trim($correo) === '') {
                return ['Cada correo o dominio autorizado debe ser texto no vacío.'];
            }

            $correo = strtolower(trim($correo));

            if (str_starts_with($correo, '@')) {
                if (! preg_match('/^@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/', $correo)) {
                    return ["El dominio «{$correo}» no tiene un formato válido."];
                }

                continue;
            }

            if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                return ["«{$correo}» no es un correo válido."];
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $escala
     * @return array<int, string>
     */
    private function erroresEscalaMora(array $escala): array
    {
        if ($escala === []) {
            return ['La escala de mora debe tener al menos un tramo.'];
        }

        foreach ($escala as $indice => $tramo) {
            if (! is_array($tramo)) {
                return ["El tramo {$indice} de la escala de mora debe ser un objeto."];
            }

            if (! isset($tramo['desde']) || ! $this->esEnteroNoNegativo($tramo['desde']) || (int) $tramo['desde'] < 1) {
                return ["El tramo {$indice} debe tener «desde» como entero mayor o igual a 1."];
            }

            if (! array_key_exists('hasta', $tramo)
                || ($tramo['hasta'] !== null && ! $this->esEnteroNoNegativo($tramo['hasta']))) {
                return ["El tramo {$indice} debe tener «hasta» como entero o null."];
            }

            if (! isset($tramo['dias_sancion']) || ! $this->esEnteroNoNegativo($tramo['dias_sancion'])) {
                return ["El tramo {$indice} debe tener «dias_sancion» como entero mayor o igual a cero."];
            }

            if ($tramo['hasta'] !== null && (int) $tramo['hasta'] < (int) $tramo['desde']) {
                return ["El tramo «{$tramo['desde']}–{$tramo['hasta']}» de la escala de mora tiene «hasta» menor que «desde»."];
            }
        }

        return $this->erroresCoberturaEscala($escala);
    }

    /**
     * BUG-0007: la escala debe cubrir todos los retrasos sin ambigüedad. Una
     * vez ordenados por «desde», los tramos tienen que empezar en el día 1,
     * ser contiguos (cada uno empieza el día siguiente al «hasta» del
     * anterior), no solaparse, y el último debe quedar abierto («hasta»
     * null), de modo que ningún retraso se quede sin tramo. Un periodo de
     * gracia se expresa con dias_sancion = 0, no dejando un hueco.
     *
     * @param  array<int, array{desde: mixed, hasta: mixed, dias_sancion: mixed}>  $escala
     * @return array<int, string>
     */
    private function erroresCoberturaEscala(array $escala): array
    {
        $tramos = array_values($escala);
        usort($tramos, fn (array $a, array $b) => (int) $a['desde'] <=> (int) $b['desde']);

        $etiqueta = fn (array $t) => $t['hasta'] === null
            ? "{$t['desde']} en adelante"
            : "{$t['desde']}–{$t['hasta']}";

        if ((int) $tramos[0]['desde'] !== 1) {
            return ["La escala de mora debe empezar en 1 día de retraso (el primer tramo empieza en {$tramos[0]['desde']}). Para un periodo de gracia usa un tramo con 0 días de sanción."];
        }

        for ($i = 1, $total = count($tramos); $i < $total; $i++) {
            $anterior = $tramos[$i - 1];
            $actual = $tramos[$i];

            if ($anterior['hasta'] === null) {
                return ["Solo el último tramo de la escala de mora puede quedar abierto: el tramo «{$etiqueta($anterior)}» se solapa con «{$etiqueta($actual)}»."];
            }

            $esperado = (int) $anterior['hasta'] + 1;
            $desde = (int) $actual['desde'];

            if ($desde <= (int) $anterior['hasta']) {
                return ["Los tramos «{$etiqueta($anterior)}» y «{$etiqueta($actual)}» de la escala de mora se solapan."];
            }

            if ($desde > $esperado) {
                $hueco = $desde - 1 === $esperado ? "{$esperado}" : "{$esperado}–".($desde - 1);

                return ["La escala de mora tiene un hueco: los retrasos de {$hueco} día(s) no quedan en ningún tramo."];
            }
        }

        // El último tramo debe quedar abierto: si se cierra (por ejemplo
        // 1–2 y 3–5), un retraso de 6 días se quedaría sin sanción, que es el
        // mismo defecto que un hueco interior. Para no sancionar a partir de
        // cierto día se usa un tramo abierto con dias_sancion = 0.
        $ultimo = $tramos[count($tramos) - 1];

        if ($ultimo['hasta'] !== null) {
            return ["La escala de mora debe terminar en un tramo abierto («hasta» null) para que ningún retraso quede sin tramo: «{$etiqueta($ultimo)}» deja fuera los retrasos mayores. Si a partir de ahí no corresponde sanción, usa un tramo abierto con 0 días de sanción."];
        }

        return [];
    }

    /**
     * BUG-0008: «duracion.categorias» es un mapa «id de categoría => días». La
     * categoría debe existir y la duración ser de al menos un día (con 0 la
     * solicitud quedaría imposible de crear).
     *
     * @param  array<mixed>  $valor
     * @return array<int, string>
     */
    private function erroresDuracionCategorias(array $valor): array
    {
        if ($valor === []) {
            return ['El parámetro «duracion.categorias» debe ser un objeto «id de categoría => días».'];
        }

        $existentes = Categoria::query()->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach ($valor as $id => $dias) {
            if (! in_array((string) $id, $existentes, true)) {
                return ["La categoría con id «{$id}» no existe."];
            }

            if (! $this->esEnteroNoNegativo($dias) || (int) $dias < 1) {
                return ["La duración de la categoría «{$id}» debe ser un entero mayor o igual a 1 día."];
            }
        }

        return [];
    }

    private function esEnteroNoNegativo(mixed $valor): bool
    {
        if (is_int($valor)) {
            return $valor >= 0;
        }

        return is_string($valor) && ctype_digit($valor);
    }
}
