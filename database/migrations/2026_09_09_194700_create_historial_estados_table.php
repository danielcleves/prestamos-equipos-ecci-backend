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
        Schema::create('historial_estados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('equipos')->cascadeOnDelete();
            // Null en el registro inicial (no hay estado previo al crear el
            // equipo). Lo llena EquipoObserver, no el controller.
            $table->string('estado_anterior')->nullable();
            $table->string('estado_nuevo');
            // Quien hizo el cambio. Nullable porque un equipo puede crearse
            // por seeder/factory sin un admin autenticado detras.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historial_estados');
    }
};
