<?php

namespace Database\Seeders;

use App\Models\Categoria;
use Illuminate\Database\Seeder;

class CategoriaSeeder extends Seeder
{
    /**
     * Categorias de ejemplo para poder probar el registro de equipos
     * (HU-03) sin quedar bloqueado por no tener ninguna todavia. No son una
     * lista cerrada — cuando exista su propio CRUD, se administran desde ahi.
     */
    private const CATEGORIAS = ['Portátil', 'Tablet', 'De mesa'];

    public function run(): void
    {
        foreach (self::CATEGORIAS as $categoria) {
            Categoria::firstOrCreate(['nombre' => $categoria]);
        }
    }
}
