<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-01: capacitación por categoría de equipo. Estar capacitado en una
     * categoría no habilita las demás. No vence: se guarda cuándo se otorgó
     * (created_at) y quién la otorgó.
     */
    public function up(): void
    {
        Schema::create('capacitaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->foreignId('otorgada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'categoria_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capacitaciones');
    }
};
