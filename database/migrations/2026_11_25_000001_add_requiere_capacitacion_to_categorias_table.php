<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-01: cada categoría decide si exige capacitación para solicitar sus
     * equipos. Reemplaza el parámetro global 'elegibilidad.requiere_capacitacion'.
     */
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->boolean('requiere_capacitacion')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('requiere_capacitacion');
        });
    }
};
