<?php

namespace Tests\Feature\Api;

use App\Models\Categoria;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RN-03 — Autorización especial de equipos restringidos.
 */
class AutorizacionEspecialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol = 'estudiante'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function equipo(int $nivel): Equipo
    {
        $categoria = Categoria::create([
            'nombre' => 'Categoría nivel '.$nivel.' '.uniqid(),
            'nivel_restriccion' => $nivel,
        ]);

        return Equipo::factory()->create([
            'categoria_id' => $categoria->id,
            'estado' => Equipo::ESTADO_DISPONIBLE,
        ]);
    }

    private function solicitar(User $usuario, Equipo $equipo): Prestamo
    {
        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Solicitud para probar autorización',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(201);

        return Prestamo::findOrFail($response->json('data.id'));
    }

    public function test_una_categoria_libre_no_requiere_autorizacion(): void
    {
        $usuario = $this->usuario();

        $prestamo = $this->solicitar($usuario, $this->equipo(0));

        $this->assertFalse($prestamo->requiere_autorizacion);
    }

    public function test_una_categoria_restringida_se_crea_con_requiere_autorizacion(): void
    {
        $usuario = $this->usuario();

        $response = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $this->equipo(1)->id,
            'motivo' => 'Solicitud de equipo especializado',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.requiere_autorizacion', true)
            ->assertJsonPath('data.autorizado', false)
            ->assertJsonPath('data.autorizado_por', null);
    }

    public function test_no_se_puede_aprobar_sin_autorizacion_especial(): void
    {
        $usuario = $this->usuario();
        $prestamo = $this->solicitar($usuario, $this->equipo(1));

        $encargado = $this->usuario('encargado');

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $response->assertStatus(422)->assertJsonValidationErrors(['prestamo']);
        $this->assertSame(
            'Este préstamo requiere autorización especial antes de aprobarse.',
            $response->json('errors.prestamo.0')
        );
    }

    public function test_autorizar_desbloquea_la_aprobacion(): void
    {
        $usuario = $this->usuario();
        $prestamo = $this->solicitar($usuario, $this->equipo(1));
        $encargado = $this->usuario('encargado');

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(200)
            ->assertJsonPath('data.autorizado', true)
            ->assertJsonPath('data.autorizado_por', $encargado->id)
            ->assertJsonPath('data.requiere_autorizacion', true);

        $this->assertNotNull($prestamo->fresh()->fecha_autorizacion);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertStatus(200)
            ->assertJsonPath('data.estado', 'aprobado');
    }

    public function test_la_autorizacion_solo_aplica_a_prestamos_restringidos(): void
    {
        $usuario = $this->usuario();
        $prestamo = $this->solicitar($usuario, $this->equipo(0));
        $encargado = $this->usuario('encargado');

        $response = $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion");

        $response->assertStatus(422)->assertJsonValidationErrors(['prestamo']);
    }

    public function test_un_usuario_comun_no_puede_autorizar(): void
    {
        $usuario = $this->usuario();
        $prestamo = $this->solicitar($usuario, $this->equipo(1));

        $this->actingAs($usuario, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(403);
    }

    public function test_no_se_autoriza_un_prestamo_ya_aprobado(): void
    {
        $usuario = $this->usuario();
        $prestamo = $this->solicitar($usuario, $this->equipo(1));
        $encargado = $this->usuario('encargado');

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(200);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertStatus(200);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(422);
    }
}
