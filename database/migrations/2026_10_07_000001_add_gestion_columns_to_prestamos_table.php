<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->foreignId('gestionado_por')->nullable()->after('recibido_por')->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_rechazo')->nullable()->after('fecha_aprobacion');
            $table->text('motivo_rechazo')->nullable()->after('motivo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prestamos', function (Blueprint $table) {
            $table->dropForeign(['gestionado_por']);
            $table->dropColumn(['gestionado_por', 'fecha_rechazo', 'motivo_rechazo']);
        });
    }
};
