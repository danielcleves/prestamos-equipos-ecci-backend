<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresiones de los defectos abiertos en la validación de release
 * develop → main (24 nov 2026): BUG-0005 … BUG-0008 (TC-SRV-048 … TC-SRV-053).
 *
 * Cada prueba describe el comportamiento CORRECTO y hoy falla contra develop.
 * Se marcan como omitidas para no dejar en rojo el check obligatorio
 * «Pruebas (PHPUnit)»; quien corrija el defecto debe borrar la llamada a
 * pendienteDeCorreccion() de la prueba correspondiente.
 *
 * Para verlas fallar contra el código actual:
 *   QA_BUGS_ABIERTOS=1 php artisan test --filter=DefectosReleaseQaTest
 *
 * Detalle: Documentación/05 - Calidad y pruebas/Release develop-main/.
 */
class DefectosReleaseQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pendienteDeCorreccion(string $motivo): void
    {
        if (! getenv('QA_BUGS_ABIERTOS')) {
            $this->markTestSkipped($motivo);
        }
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    /**
     * Desactiva al usuario con otra cuenta admin y deja el guard sin la
     * identidad cacheada, para que la siguiente petición se autentique solo
     * con el token Bearer que el usuario ya tenía.
     */
    private function desactivarConTokenPrevio(User $usuario): string
    {
        $token = $usuario->createToken('api')->plainTextToken;

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->patchJson("/api/usuarios/{$usuario->id}/desactivar")
            ->assertOk();

        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** TC-SRV-048 · BUG-0005 · RN-01 / HU-02: un solicitante desactivado no puede seguir solicitando con su token. */
    public function test_un_solicitante_desactivado_no_puede_solicitar_con_un_token_previo(): void
    {
        $this->pendienteDeCorreccion('BUG-0005: desactivar no revoca los tokens Sanctum ni se revisa is_active en cada petición.');

        $estudiante = $this->usuario('estudiante');
        $token = $this->desactivarConTokenPrevio($estudiante);
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);

        $respuesta = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Solicitud con la cuenta desactivada',
            'fecha_inicio' => now()->addHour()->toIso8601String(),
            'fecha_devolucion_estimada' => now()->addDays(2)->toIso8601String(),
        ]);

        $this->assertContains($respuesta->status(), [401, 403], 'Obtenido: '.$respuesta->status());
        $this->assertSame(0, Prestamo::count());
    }

    /** TC-SRV-049 · BUG-0005 · HU-02: un admin desactivado no puede seguir administrando con su token. */
    public function test_un_admin_desactivado_no_puede_crear_usuarios_con_un_token_previo(): void
    {
        $this->pendienteDeCorreccion('BUG-0005: desactivar no revoca los tokens Sanctum ni se revisa is_active en cada petición.');

        $adminDesactivado = $this->usuario('admin');
        $token = $this->desactivarConTokenPrevio($adminDesactivado);

        $respuesta = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/usuarios', [
            'name' => 'Creado por un admin desactivado',
            'email' => 'creado.por.desactivado@ecci.edu.co',
            'password' => 'Password123!',
            'role' => 'admin',
        ]);

        $this->assertContains($respuesta->status(), [401, 403], 'Obtenido: '.$respuesta->status());
        $this->assertDatabaseMissing('users', ['email' => 'creado.por.desactivado@ecci.edu.co']);
    }

    /** TC-SRV-050 · BUG-0006 · RN-04: los días de mora se cuentan en días calendario de Colombia, no de UTC. */
    public function test_los_dias_de_mora_se_cuentan_en_la_zona_horaria_de_negocio(): void
    {
        $this->pendienteDeCorreccion('BUG-0006: SancionService::diasDeRetraso() usa startOfDay() en UTC.');

        // 13 dic 00:30 en Bogotá = 13 dic 05:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-12-13 05:30:00', 'UTC'));

        $solicitante = $this->usuario('estudiante');
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_inicio' => Carbon::parse('2026-12-08 08:00:00', 'America/Bogota')->utc(),
            'fecha_entrega_real' => Carbon::parse('2026-12-08 08:00:00', 'America/Bogota')->utc(),
            // Fin del día 10 dic en Bogotá (= 11 dic 04:59:59 UTC).
            'fecha_devolucion_estimada' => Carbon::parse('2026-12-10 23:59:59', 'America/Bogota')->utc(),
        ]);

        $this->actingAs($this->usuario('encargado'), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'fecha_devolucion_real' => '2026-12-13 00:30:00',
            ])->assertOk();

        // 10 dic → 13 dic: 3 días calendario de retraso → tramo 3–5 → 7 días.
        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();
        $this->assertSame(3, $sancion->dias_retraso);
        $this->assertSame(7, $sancion->dias_sancion);
    }

    /** TC-SRV-051 · BUG-0007 · RN-04: la escala de mora rechaza un tramo con «hasta» menor que «desde». */
    public function test_la_escala_de_mora_rechaza_un_tramo_con_hasta_menor_que_desde(): void
    {
        $this->pendienteDeCorreccion('BUG-0007: UpdateConfiguracionRequest no valida la coherencia de los tramos de mora.escala.');

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['mora.escala' => [
                ['desde' => 5, 'hasta' => 2, 'dias_sancion' => 3],
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    /** TC-SRV-052 · BUG-0007 · RN-04: la escala de mora rechaza huecos que dejarían retrasos sin sanción. */
    public function test_la_escala_de_mora_rechaza_huecos_entre_tramos(): void
    {
        $this->pendienteDeCorreccion('BUG-0007: UpdateConfiguracionRequest no valida la coherencia de los tramos de mora.escala.');

        // Sin el tramo 3–5, un retraso de 3, 4 o 5 días no tendría sanción.
        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['mora.escala' => [
                ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 3],
                ['desde' => 6, 'hasta' => null, 'dias_sancion' => 15],
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    /** TC-SRV-053 · BUG-0008 · RN-06: la duración por categoría rechaza ids de categoría inexistentes. */
    public function test_la_duracion_por_categoria_rechaza_una_categoria_inexistente(): void
    {
        $this->pendienteDeCorreccion('BUG-0008: duracion.categorias no valida que la categoría exista (cupos.roles y duracion.roles_extra sí lo hacen).');

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['duracion.categorias' => ['999999' => 5]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.duracion.categorias']);
    }
}
