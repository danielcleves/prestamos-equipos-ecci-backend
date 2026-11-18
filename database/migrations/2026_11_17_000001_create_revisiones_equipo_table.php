<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * HU-12: la revision del equipo al momento de la devolucion pasa a ser
     * una entidad propia, en vez de vivir solo en columnas de 'prestamos'.
     */
    public function up(): void
    {
        Schema::create('revisiones_equipo', function (Blueprint $table) {
            $table->id();

            // Una sola revision por prestamo: la revision de devolucion es un
            // hecho unico del ciclo de vida, no una bitacora.
            $table->foreignId('prestamo_id')->unique()->constrained('prestamos')->restrictOnDelete();

            // Se guarda tambien el equipo para poder consultar su historial de
            // revisiones sin pasar por 'prestamos' (HU-12 / KAN-130).
            $table->foreignId('equipo_id')->constrained('equipos')->restrictOnDelete();

            // Condicion reportada (App\Enums\CondicionEquipo).
            $table->string('condicion');

            // Derivado de la condicion, pero persistido: permite filtrar y
            // contar revisiones con novedad sin conocer los valores del enum.
            $table->boolean('tiene_danos');

            $table->text('observaciones')->nullable();

            // Personal administrativo que realizo la revision.
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('fecha_revision');

            // HU-31 (incidencias) todavia no existe como tabla. Se deja la
            // columna indexada y SIN foreign key a proposito, para poder
            // vincular la incidencia despues sin una segunda migracion.
            $table->unsignedBigInteger('incidencia_id')->nullable()->index();

            $table->timestamps();

            // Consultas de KAN-130: revisiones de un equipo, mas recientes
            // primero, y conteo de revisiones con novedad.
            $table->index(['equipo_id', 'fecha_revision']);
            $table->index('tiene_danos');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('revisiones_equipo');
    }
};
