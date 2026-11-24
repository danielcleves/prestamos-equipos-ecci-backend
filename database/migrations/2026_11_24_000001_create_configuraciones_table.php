<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Iteración 2 (RN-02, RN-04, RN-06, RN-08): parámetros de negocio
     * administrables por el admin sin tocar el código ni el archivo .env.
     *
     * 'valor' se guarda como texto y se interpreta según 'tipo': los JSON
     * (cupos por rol, escala de mora) viajan serializados en esa columna.
     */
    public function up(): void
    {
        Schema::create('configuraciones', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->text('valor');
            $table->string('tipo');   // int, bool, json
            $table->string('grupo');  // cupos, duracion, mora, danos, elegibilidad
            $table->string('descripcion')->nullable();
            $table->timestamps();

            $table->index('grupo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones');
    }
};
