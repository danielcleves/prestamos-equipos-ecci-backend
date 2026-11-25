<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CLAVE = 'elegibilidad.requiere_capacitacion';

    /**
     * RN-01: se retira el parámetro global de capacitación; ahora lo decide
     * cada categoría (categorias.requiere_capacitacion).
     */
    public function up(): void
    {
        DB::table('configuraciones')->where('clave', self::CLAVE)->delete();
    }

    /**
     * Restaura la fila con su valor inicial (no exigir capacitación).
     */
    public function down(): void
    {
        if (DB::table('configuraciones')->where('clave', self::CLAVE)->exists()) {
            return;
        }

        DB::table('configuraciones')->insert([
            'clave' => self::CLAVE,
            'valor' => '0',
            'tipo' => 'bool',
            'grupo' => 'elegibilidad',
            'descripcion' => 'Exige estar capacitado para solicitar préstamos.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
