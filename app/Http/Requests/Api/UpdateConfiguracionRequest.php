<?php

namespace App\Http\Requests\Api;

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

        // cupos.roles, duracion.categorias y duracion.roles_extra son mapas de
        // nombre/id a número de días o de cupos.
        foreach ($valor as $nombre => $numero) {
            if (! $this->esEnteroNoNegativo($numero)) {
                return ["El valor de «{$nombre}» en «{$clave}» debe ser un entero mayor o igual a cero."];
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
