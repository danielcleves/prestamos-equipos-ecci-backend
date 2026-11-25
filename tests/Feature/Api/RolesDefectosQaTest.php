<?php

namespace Tests\Feature\Api;

use App\Models\Configuracion;
use App\Models\Rol;
use App\Models\User;
use App\Services\Parametros;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresión de los defectos que encontró QA en EVD-031 (RN-02).
 */
class RolesDefectosQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /** TC-ROL-060 — Borrar un rol limpia sus días extra (defecto 1). */
    public function test_borrar_un_rol_limpia_sus_dias_extra(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles', ['etiqueta' => 'Profesor', 'cupo_prestamos' => 4])
            ->assertCreated()->json('data.id');
        Configuracion::establecer('duracion.roles_extra', ['estudiante' => 0, 'profesor' => 9]);

        $this->deleteJson("/api/roles/{$id}")->assertNoContent();
        $this->postJson('/api/roles', ['etiqueta' => 'Profesor', 'cupo_prestamos' => 4])->assertCreated();

        $this->assertSame(7, app(Parametros::class)->duracionMaxima(0, 'profesor'));
        $this->assertSame(['estudiante' => 0], app(Parametros::class)->valores()['duracion.roles_extra']);
    }

    /** TC-ROL-061 — duracion.roles_extra rechaza roles inexistentes (defecto 2). */
    public function test_dias_extra_de_un_rol_inexistente_es_422(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['duracion.roles_extra' => ['fantasma' => 30]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.duracion.roles_extra']);
    }

    /** TC-ROL-062 — Sin rol predeterminado el registro no deja usuarios huérfanos (defecto 3). */
    public function test_registro_sin_rol_de_solicitante_no_crea_el_usuario(): void
    {
        Rol::whereNotIn('name', Rol::SISTEMA)->delete();

        $this->postJson('/api/register', [
            'name' => 'Sin Rol',
            'email' => 'x@ecci.edu.co',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(503);

        $this->assertDatabaseMissing('users', ['email' => 'x@ecci.edu.co']);
    }

    /** TC-ROL-063 — Mensaje claro para etiquetas sin caracteres latinos (defecto 4). */
    public function test_etiqueta_no_latina_explica_el_motivo(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/roles', ['etiqueta' => '日本語テスト', 'cupo_prestamos' => 1])
            ->assertStatus(422)
            ->assertJsonPath('errors.etiqueta.0', 'El nombre del rol debe contener letras del alfabeto latino o números.');
    }

    /** TC-ROL-064 — Un rol de solicitante no puede renombrarse como uno de sistema (defecto 5). */
    public function test_un_solicitante_no_puede_llamarse_encargado(): void
    {
        $estudiante = Rol::where('name', 'estudiante')->firstOrFail();

        foreach (['Encargado', 'ADMIN', 'Usuario'] as $etiqueta) {
            $this->actingAs($this->admin(), 'sanctum')
                ->patchJson("/api/roles/{$estudiante->id}", ['etiqueta' => $etiqueta])
                ->assertStatus(422);
        }
    }

    /** TC-ROL-065 — Duplicado con tildes y distinto caso, también en SQLite (defecto 6). */
    public function test_duplicado_con_tildes_y_distinto_caso(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/roles', ['etiqueta' => 'COORDINACIÓN', 'cupo_prestamos' => 2])->assertCreated();
        $otro = $this->postJson('/api/roles', ['etiqueta' => 'Profesor', 'cupo_prestamos' => 2])->json('data.id');

        $this->patchJson("/api/roles/{$otro}", ['etiqueta' => 'Coordinación'])->assertStatus(422);
    }
}
