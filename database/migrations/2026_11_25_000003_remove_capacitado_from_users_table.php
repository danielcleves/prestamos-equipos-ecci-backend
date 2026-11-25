<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-01: la capacitación global se reemplaza por la capacitación por
     * categoría (tabla 'capacitaciones'). Decisión del PO: nadie arranca
     * capacitado, así que los valores de 'capacitado' no se migran.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('capacitado');
        });
    }

    /**
     * Restaura la columna con sus atributos originales (default true).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('capacitado')->default(true);
        });
    }
};
