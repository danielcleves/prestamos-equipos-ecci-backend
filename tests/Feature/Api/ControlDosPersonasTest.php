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
 * BUG-0009 — control de dos personas en las categorías restringidas (RN-03).
 *
 * Decisión conservadora pendiente de confirmar con el PO: quien solicitó un
 * equipo restringido no puede autorizarlo ni aprobarlo, aunque pertenezca al
 * personal administrativo.
 */
class ControlDosPersonasTest extends TestCase
{
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

    private function equipo(): Equipo
    {
        return Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
    }

    private function prestamo(User $solicitante, bool $restringido): Prestamo
    {
        return Prestamo::factory()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $this->equipo()->id,
            'estado' => EstadoPrestamo::Solicitado,
            'requiere_autorizacion' => $restringido,
        ]);
    }

    public function test_el_personal_no_puede_autorizar_su_propia_solicitud(): void
    {
        $solicitante = $this->usuario('encargado');
        $prestamo = $this->prestamo($solicitante, true);

        $response = $this->actingAs($solicitante, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion");

        $response->assertStatus(422)->assertJsonValidationErrors(['prestamo']);
        $this->assertStringContainsString('tu propia', $response->json('errors.prestamo.0'));

        $this->assertNull($prestamo->fresh()->autorizado_por);
    }

    public function test_el_personal_no_puede_aprobar_su_propia_solicitud_restringida(): void
    {
        $solicitante = $this->usuario('encargado');
        $prestamo = $this->prestamo($solicitante, true);

        // Otro miembro del personal sí puede autorizarla.
        $otro = $this->usuario('encargado');
        $this->actingAs($otro, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertOk();

        $response = $this->actingAs($solicitante, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion");

        $response->assertStatus(422)->assertJsonValidationErrors(['prestamo']);
        $this->assertStringContainsString('tu propia', $response->json('errors.prestamo.0'));

        $this->assertSame(EstadoPrestamo::Solicitado, $prestamo->fresh()->estado);

        // Y el otro miembro del personal puede aprobarla.
        $this->actingAs($otro, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertOk()
            ->assertJsonPath('data.estado', 'aprobado');
    }

    public function test_el_control_no_alcanza_a_una_solicitud_no_restringida(): void
    {
        $solicitante = $this->usuario('encargado');
        $prestamo = $this->prestamo($solicitante, false);

        $this->actingAs($solicitante, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertOk()
            ->assertJsonPath('data.estado', 'aprobado');
    }
}
