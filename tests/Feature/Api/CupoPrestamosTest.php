<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-02 — Cupo de préstamos vivos por rol.
 */
class CupoPrestamosTest extends TestCase
{
    use ConfiguraParametros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function prestamoVivo(User $user): Prestamo
    {
        return Prestamo::factory()->create([
            'usuario_id' => $user->id,
            'estado' => EstadoPrestamo::Solicitado,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'equipo_id' => Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE])->id,
            'motivo' => 'Solicitud para probar el cupo',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ];
    }

    public function test_el_cupo_exacto_permite_la_siguiente_solicitud(): void
    {
        $this->parametro('cupos.roles', ['usuario' => 2, 'encargado' => 5, 'admin' => 10]);
        $usuario = $this->usuario('usuario');
        $this->prestamoVivo($usuario);

        // Con cupo 2 y 1 préstamo vivo, la segunda solicitud entra justo.
        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload())
            ->assertStatus(201);

        $this->assertSame(2, Prestamo::where('usuario_id', $usuario->id)->count());
    }

    public function test_un_prestamo_por_encima_del_cupo_es_rechazado_con_el_cupo_en_el_mensaje(): void
    {
        $this->parametro('cupos.roles', ['usuario' => 2, 'encargado' => 5, 'admin' => 10]);
        $usuario = $this->usuario('usuario');
        $this->prestamoVivo($usuario);
        $this->prestamoVivo($usuario);

        $response = $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload());

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('límite máximo de 2', $response->json('errors.usuario_id.0'));
        $this->assertSame(2, Prestamo::where('usuario_id', $usuario->id)->count());
    }

    public function test_el_cupo_cambia_segun_el_rol_del_solicitante(): void
    {
        $this->parametro('cupos.roles', ['usuario' => 1, 'encargado' => 2, 'admin' => 10]);

        $usuario = $this->usuario('usuario');
        $encargado = $this->usuario('encargado');
        $this->prestamoVivo($usuario);
        $this->prestamoVivo($encargado);

        // El usuario ya agotó su cupo de 1...
        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/prestamos', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['usuario_id']);

        // ...mientras que el encargado con 1 vivo todavía puede llegar a 2.
        $this->actingAs($encargado, 'sanctum')
            ->postJson('/api/prestamos', $this->payload())
            ->assertStatus(201);
    }
}
