<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-08: incidencia abierta cuando un equipo se devuelve con daños o
     * requiere mantenimiento. Restringe temporalmente al solicitante hasta
     * que el personal la cierre.
     *
     * revisiones_equipo.incidencia_id ya existe (HU-12) y se rellena al crear
     * la incidencia; no se agrega la foreign key allí para no crear una
     * dependencia circular entre ambas tablas.
     */
    public function up(): void
    {
        Schema::create('incidencias', function (Blueprint $table) {
            $table->id();

            // Una incidencia por préstamo: es el hecho de la devolución.
            $table->foreignId('prestamo_id')->unique()->constrained('prestamos')->restrictOnDelete();
            $table->foreignId('equipo_id')->constrained('equipos')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->foreignId('revision_id')->nullable()->constrained('revisiones_equipo')->nullOnDelete();

            $table->text('descripcion');

            // abierta, en_revision, cerrada
            $table->string('estado')->default('abierta');

            $table->dateTime('restringe_hasta');

            $table->foreignId('resuelta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('fecha_cierre')->nullable();

            $table->timestamps();

            // Elegibilidad: incidencia abierta y vigente de un usuario.
            $table->index(['user_id', 'estado', 'restringe_hasta']);
            $table->index(['equipo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencias');
    }
};
