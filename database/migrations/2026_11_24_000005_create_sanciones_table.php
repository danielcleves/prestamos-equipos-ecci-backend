<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-04: sanción escalonada por mora. Se crea al registrar una devolución
     * tardía y bloquea nuevas solicitudes mientras esté activa y vigente.
     */
    public function up(): void
    {
        Schema::create('sanciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // El préstamo puede desaparecer por borrado administrativo; la
            // sanción se conserva como registro del usuario.
            $table->foreignId('prestamo_id')->nullable()->constrained('prestamos')->nullOnDelete();

            $table->string('motivo')->default('mora');
            $table->unsignedSmallInteger('dias_retraso');
            $table->unsignedSmallInteger('dias_sancion');
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_fin');
            // activa, cumplida, anulada
            $table->string('estado')->default('activa');

            $table->timestamps();

            // Consulta de elegibilidad: sanciones vigentes de un usuario.
            $table->index(['user_id', 'estado']);
            $table->index('fecha_fin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sanciones');
    }
};
