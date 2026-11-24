<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-03: autorización especial de las solicitudes sobre equipos
     * restringidos (categorias.nivel_restriccion >= 1).
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->boolean('requiere_autorizacion')->default(false);
            $table->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_autorizacion')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('autorizado_por');
            $table->dropColumn(['requiere_autorizacion', 'fecha_autorizacion']);
        });
    }
};
