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
        Schema::create('equipos', function (Blueprint $table) {
            $table->id();
            // Codigo/serial: identificador unico que pide HU-03 ("no se
            // deben permitir identificadores duplicados").
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->foreignId('categoria_id')->constrained('categorias')->restrictOnDelete();
            $table->text('descripcion')->nullable();
            // Sin default de columna a proposito: el estado inicial lo
            // asigna EquipoController::store explicitamente (ver
            // Equipo::ESTADOS), no la base de datos.
            $table->string('estado');
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipos');
    }
};
