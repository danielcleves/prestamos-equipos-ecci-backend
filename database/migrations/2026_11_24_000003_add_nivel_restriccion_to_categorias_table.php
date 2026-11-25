<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-03 / RN-01: nivel de restricción del tipo de equipo.
     * 0 libre, 1 requiere autorización adicional, 2 solo personal
     * administrativo.
     */
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->unsignedTinyInteger('nivel_restriccion')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('nivel_restriccion');
        });
    }
};
