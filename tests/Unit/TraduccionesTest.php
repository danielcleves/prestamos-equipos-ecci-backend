<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TraduccionesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function archivosIdiomaProvider(): array
    {
        return [
            'validation' => ['validation.php'],
            'auth' => ['auth.php'],
            'passwords' => ['passwords.php'],
            'pagination' => ['pagination.php'],
            'errores' => ['errores.php'],
        ];
    }

    /**
     * Aplana recursivamente un arreglo asociativo con notacion de punto.
     *
     * @param  array<string, mixed>  $array
     * @return array<string, string>
     */
    private function aplanarClaves(array $array, string $prefijo = ''): array
    {
        $resultado = [];

        foreach ($array as $clave => $valor) {
            $claveCompleta = $prefijo ? "{$prefijo}.{$clave}" : (string) $clave;

            if (is_array($valor)) {
                $resultado = array_merge($resultado, $this->aplanarClaves($valor, $claveCompleta));
            } else {
                $resultado[$claveCompleta] = (string) $valor;
            }
        }

        return $resultado;
    }

    #[DataProvider('archivosIdiomaProvider')]
    public function test_todas_las_claves_en_ingles_existen_en_espanol(string $archivo): void
    {
        $rutaEn = dirname(__DIR__, 2)."/lang/en/{$archivo}";
        $rutaEs = dirname(__DIR__, 2)."/lang/es/{$archivo}";

        $this->assertFileExists($rutaEn);
        $this->assertFileExists($rutaEs);

        $en = $this->aplanarClaves(require $rutaEn);
        $es = $this->aplanarClaves(require $rutaEs);

        $faltantesEnEs = array_diff(array_keys($en), array_keys($es));

        $this->assertEmpty(
            $faltantesEnEs,
            "En {$archivo} faltan las siguientes claves en español: ".implode(', ', $faltantesEnEs)
        );
    }

    #[DataProvider('archivosIdiomaProvider')]
    public function test_ningun_valor_en_espanol_es_identico_al_ingles_salvo_lista_blanca(string $archivo): void
    {
        $rutaEn = dirname(__DIR__, 2)."/lang/en/{$archivo}";
        $rutaEs = dirname(__DIR__, 2)."/lang/es/{$archivo}";

        $en = $this->aplanarClaves(require $rutaEn);
        $es = $this->aplanarClaves(require $rutaEs);

        // Lista blanca para claves cuyos valores pueden o deben ser identicos
        // (por ejemplo, simbolos o claves de ejemplo de Laravel).
        $listaBlanca = [
            'validation.php' => [
                'custom.attribute-name.rule-name',
            ],
            'auth.php' => [],
            'passwords.php' => [],
            'pagination.php' => [],
            'errores.php' => [],
        ];

        $identicos = [];

        foreach ($es as $clave => $valorEs) {
            if (! isset($en[$clave])) {
                continue;
            }

            if (in_array($clave, $listaBlanca[$archivo] ?? [], true)) {
                continue;
            }

            if ($valorEs === $en[$clave]) {
                $identicos[] = "{$clave} => '{$valorEs}'";
            }
        }

        $this->assertEmpty(
            $identicos,
            "En {$archivo} los siguientes valores estan sin traducir (identicos al ingles): ".implode(', ', $identicos)
        );
    }
}
