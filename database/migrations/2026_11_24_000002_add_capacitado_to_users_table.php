<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-01: requisito de capacitación. Por defecto todos los usuarios quedan
     * capacitados para no romper el flujo actual; el admin lo desmarca cuando
     * corresponda.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('capacitado')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('capacitado');
        });
    }
};
