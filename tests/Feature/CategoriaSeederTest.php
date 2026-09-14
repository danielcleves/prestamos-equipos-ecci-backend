<?php

namespace Tests\Feature;

use App\Models\Categoria;
use Database\Seeders\CategoriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoriaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_crea_categorias_de_ejemplo(): void
    {
        $this->seed(CategoriaSeeder::class);

        $this->assertSame(3, Categoria::count());
    }

    public function test_correr_el_seeder_dos_veces_no_duplica_categorias(): void
    {
        $this->seed(CategoriaSeeder::class);
        $this->seed(CategoriaSeeder::class);

        $this->assertSame(3, Categoria::count());
    }
}
