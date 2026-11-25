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
        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();

            // Solicitante del prestamo
            $table->foreignId('usuario_id')->constrained('users')->restrictOnDelete();

            // Equipo solicitado
            $table->foreignId('equipo_id')->constrained('equipos')->restrictOnDelete();

            // Estado del prestamo (solicitado, aprobado, entregado, devuelto, rechazado, cancelado)
            $table->string('estado');

            // Motivo del prestamo (confirmado por el PO en KAN-93, obligatorio)
            $table->text('motivo');

            // Fechas del ciclo de vida del prestamo
            $table->dateTime('fecha_solicitud');
            $table->dateTime('fecha_inicio');
            $table->dateTime('fecha_devolucion_estimada');
            $table->dateTime('fecha_aprobacion')->nullable();
            $table->dateTime('fecha_entrega_real')->nullable();
            $table->dateTime('fecha_devolucion_real')->nullable();

            // Condicion del equipo reportada en entrega y devolucion
            $table->string('condicion_entrega')->nullable();
            $table->string('condicion_devolucion')->nullable();

            // Personal que interviene en la entrega y devolucion
            $table->foreignId('entregado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recibido_por')->nullable()->constrained('users')->nullOnDelete();

            // Observaciones registradas en entrega y devolución (visibles solo para personal administrativo)
            $table->text('observaciones_entrega')->nullable();
            $table->text('observaciones_devolucion')->nullable();

            $table->timestamps();

            // Indices requeridos para optimizar consultas de disponibilidad, limites de usuario, listado de activos y deteccion de retrasos
            $table->index('estado');
            $table->index(['equipo_id', 'estado']);
            $table->index(['usuario_id', 'estado']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prestamos');
    }
};
