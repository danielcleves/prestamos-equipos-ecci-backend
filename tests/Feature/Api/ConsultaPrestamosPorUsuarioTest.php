<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HU-10 / KAN-118: el solicitante consulta sus propios préstamos.
 *
 * GET /api/prestamos ya cubría la necesidad (filtra por usuario_id cuando
 * quien consulta no es personal, y PrestamoResource ya expone equipo, fecha
 * de entrega, fecha máxima de devolución y estado). Estas pruebas cierran los
 * huecos de cobertura; no hizo falta endpoint ni campos nuevos.
 */
class ConsultaPrestamosPorUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function solicitante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    private function personal(string $rol = 'encargado'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    // ---------------------------------------------------------------
    // KAN-118: los datos que necesita el solicitante
    // ---------------------------------------------------------------

    public function test_el_solicitante_ve_equipo_fecha_de_entrega_fecha_maxima_y_estado(): void
    {
        $usuario = $this->solicitante();
        $equipo = Equipo::factory()->create([
            'codigo' => 'PORT-077',
            'nombre' => 'Laptop Lenovo ThinkPad',
        ]);

        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $usuario->id,
            'equipo_id' => $equipo->id,
        ]);

        $response = $this->actingAs($usuario, 'sanctum')->getJson('/api/prestamos');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            // Equipo
            ->assertJsonPath('data.0.equipo.id', $equipo->id)
            ->assertJsonPath('data.0.equipo.codigo', 'PORT-077')
            ->assertJsonPath('data.0.equipo.nombre', 'Laptop Lenovo ThinkPad')
            // Estado
            ->assertJsonPath('data.0.estado', EstadoPrestamo::Entregado->value)
            ->assertJsonPath('data.0.estado_etiqueta', 'Entregado');

        $item = $response->json('data.0');

        // Fecha de entrega real y fecha máxima de devolución
        $this->assertNotNull($item['fecha_entrega_real'], 'falta la fecha de entrega');
        $this->assertNotNull($item['fecha_devolucion_estimada'], 'falta la fecha máxima de devolución');

        $this->assertSame(
            $prestamo->fecha_entrega_real->copy()->setTimezone('America/Bogota')->toIso8601String(),
            $item['fecha_entrega_real']
        );
        $this->assertSame(
            $prestamo->fecha_devolucion_estimada->copy()->setTimezone('America/Bogota')->toIso8601String(),
            $item['fecha_devolucion_estimada']
        );
    }

    public function test_la_fecha_de_entrega_es_nula_mientras_no_se_haya_entregado(): void
    {
        $usuario = $this->solicitante();
        Prestamo::factory()->aprobado()->create(['usuario_id' => $usuario->id]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos')
            ->assertStatus(200)
            ->assertJsonPath('data.0.fecha_entrega_real', null)
            ->assertJsonPath('data.0.estado', EstadoPrestamo::Aprobado->value);
    }

    public function test_el_solicitante_ve_sus_prestamos_en_todos_los_estados(): void
    {
        $usuario = $this->solicitante();

        Prestamo::factory()->solicitado()->create(['usuario_id' => $usuario->id]);
        Prestamo::factory()->aprobado()->create(['usuario_id' => $usuario->id]);
        Prestamo::factory()->entregado()->create(['usuario_id' => $usuario->id]);
        Prestamo::factory()->rechazado()->create(['usuario_id' => $usuario->id]);
        Prestamo::factory()->devuelto()->create(['usuario_id' => $usuario->id]);

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_un_solicitante_sin_prestamos_recibe_lista_vacia(): void
    {
        $usuario = $this->solicitante();

        // Préstamos de otra persona: no debe ver ninguno
        Prestamo::factory()->count(3)->entregado()->create();

        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/prestamos')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function test_el_solicitante_no_ve_las_observaciones_reservadas_al_personal(): void
    {
        $usuario = $this->solicitante();
        Prestamo::factory()->devuelto()->create([
            'usuario_id' => $usuario->id,
            'observaciones_entrega' => 'Nota interna de entrega',
            'observaciones_devolucion' => 'Nota interna de devolución',
        ]);

        $response = $this->actingAs($usuario, 'sanctum')->getJson('/api/prestamos');

        $response->assertStatus(200);

        $item = $response->json('data.0');
        $this->assertArrayNotHasKey('observaciones_entrega', $item);
        $this->assertArrayNotHasKey('observaciones_devolucion', $item);

        $cuerpo = $response->getContent();
        $this->assertStringNotContainsString('Nota interna de entrega', $cuerpo);
        $this->assertStringNotContainsString('Nota interna de devolución', $cuerpo);
    }
}
