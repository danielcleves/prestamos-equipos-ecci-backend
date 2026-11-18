<?php

namespace Tests\Feature\Services;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use App\Services\RevisionEquipoService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HU-12 / KAN-126: servicio de registro de la revision.
 */
class RevisionEquipoServiceTest extends TestCase
{
    use RefreshDatabase;

    private RevisionEquipoService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->service = app(RevisionEquipoService::class);
    }

    private function personal(string $rol = 'encargado'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    public function test_registra_revision_en_buen_estado(): void
    {
        $encargado = $this->personal();
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        $prestamo = Prestamo::factory()->entregado()->create(['equipo_id' => $equipo->id]);

        $revision = $this->service->registrar($prestamo, $encargado, [
            'condicion' => CondicionEquipo::Bueno,
        ]);

        $this->assertSame($prestamo->id, $revision->prestamo_id);
        $this->assertSame($equipo->id, $revision->equipo_id);
        $this->assertSame(CondicionEquipo::Bueno, $revision->condicion);
        $this->assertFalse($revision->tiene_danos);
        $this->assertNull($revision->observaciones);
        $this->assertSame($encargado->id, $revision->revisado_por);
        $this->assertNotNull($revision->fecha_revision);

        $this->assertDatabaseHas('revisiones_equipo', [
            'prestamo_id' => $prestamo->id,
            'equipo_id' => $equipo->id,
            'condicion' => CondicionEquipo::Bueno->value,
            'revisado_por' => $encargado->id,
        ]);
    }

    public function test_registra_revision_con_danos_y_deriva_tiene_danos(): void
    {
        $admin = $this->personal('admin');
        $prestamo = Prestamo::factory()->entregado()->create();

        $revision = $this->service->registrar($prestamo, $admin, [
            'condicion' => CondicionEquipo::ConDanos,
            'observaciones' => '  Pantalla con una fisura en la esquina  ',
        ]);

        $this->assertTrue($revision->tiene_danos);
        $this->assertSame(CondicionEquipo::ConDanos, $revision->condicion);
        // Las observaciones se almacenan recortadas, como el resto del modulo.
        $this->assertSame('Pantalla con una fisura en la esquina', $revision->observaciones);
    }

    public function test_registra_revision_que_requiere_mantenimiento(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        $revision = $this->service->registrar($prestamo, $encargado, [
            'condicion' => CondicionEquipo::RequiereMantenimiento,
            'observaciones' => 'Ventilador con ruido anormal',
        ]);

        $this->assertTrue($revision->tiene_danos);
        $this->assertSame(CondicionEquipo::RequiereMantenimiento, $revision->condicion);
    }

    public function test_acepta_la_condicion_como_cadena(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        $revision = $this->service->registrar($prestamo, $encargado, [
            'condicion' => 'con_danos',
            'observaciones' => 'Falta el cargador',
        ]);

        $this->assertSame(CondicionEquipo::ConDanos, $revision->condicion);
        $this->assertTrue($revision->tiene_danos);
    }

    public function test_rechaza_a_un_usuario_que_no_es_personal_administrativo(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');
        $prestamo = Prestamo::factory()->entregado()->create();

        try {
            $this->service->registrar($prestamo, $usuario, [
                'condicion' => CondicionEquipo::Bueno,
            ]);
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revisado_por', $e->errors());
            $this->assertSame(
                'Solo el personal de préstamo puede registrar la revisión del equipo.',
                $e->errors()['revisado_por'][0]
            );
        }

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_rechaza_a_un_usuario_sin_ningun_rol(): void
    {
        $sinRol = User::factory()->create();
        $prestamo = Prestamo::factory()->entregado()->create();

        $this->expectException(ValidationException::class);

        try {
            $this->service->registrar($prestamo, $sinRol, [
                'condicion' => CondicionEquipo::Bueno,
            ]);
        } finally {
            $this->assertDatabaseCount('revisiones_equipo', 0);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function estadosNoEntregados(): array
    {
        return [
            'solicitado' => ['solicitado'],
            'aprobado' => ['aprobado'],
            'rechazado' => ['rechazado'],
            'devuelto' => ['devuelto'],
        ];
    }

    #[DataProvider('estadosNoEntregados')]
    public function test_rechaza_si_el_prestamo_no_esta_entregado(string $estado): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->{$estado}()->create();

        try {
            $this->service->registrar($prestamo, $encargado, [
                'condicion' => CondicionEquipo::Bueno,
            ]);
            $this->fail("Se esperaba una ValidationException para el estado {$estado}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('estado', $e->errors());
            $this->assertStringContainsString(
                'no se le puede registrar la revisión de devolución',
                $e->errors()['estado'][0]
            );
        }

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_rechaza_si_el_prestamo_no_existe(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();
        $id = $prestamo->id;
        $prestamo->delete();

        // Instancia en memoria que ya no tiene fila en base de datos.
        $fantasma = new Prestamo;
        $fantasma->id = $id;
        $fantasma->exists = true;

        try {
            $this->service->registrar($fantasma, $encargado, [
                'condicion' => CondicionEquipo::Bueno,
            ]);
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prestamo_id', $e->errors());
            $this->assertSame('El préstamo indicado no existe.', $e->errors()['prestamo_id'][0]);
        }
    }

    public function test_rechaza_una_segunda_revision_para_el_mismo_prestamo(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        $this->service->registrar($prestamo, $encargado, [
            'condicion' => CondicionEquipo::Bueno,
        ]);

        try {
            $this->service->registrar($prestamo, $encargado, [
                'condicion' => CondicionEquipo::ConDanos,
                'observaciones' => 'Segundo intento',
            ]);
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prestamo_id', $e->errors());
            $this->assertSame(
                'El préstamo ya cuenta con una revisión registrada.',
                $e->errors()['prestamo_id'][0]
            );
        }

        $this->assertDatabaseCount('revisiones_equipo', 1);
    }

    public function test_rechaza_una_condicion_invalida_o_ausente(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        foreach ([[], ['condicion' => 'excelente'], ['condicion' => null]] as $datos) {
            try {
                $this->service->registrar($prestamo, $encargado, $datos);
                $this->fail('Se esperaba una ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('condicion', $e->errors());
            }
        }

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_exige_observaciones_cuando_hay_novedad(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        foreach ([null, '', '     '] as $observaciones) {
            try {
                $this->service->registrar($prestamo, $encargado, [
                    'condicion' => CondicionEquipo::ConDanos,
                    'observaciones' => $observaciones,
                ]);
                $this->fail('Se esperaba una ValidationException.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('observaciones', $e->errors());
                $this->assertSame(
                    'Las observaciones son obligatorias cuando el equipo no se devuelve en buen estado.',
                    $e->errors()['observaciones'][0]
                );
            }
        }

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_no_exige_observaciones_cuando_la_condicion_es_bueno(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        $revision = $this->service->registrar($prestamo, $encargado, [
            'condicion' => CondicionEquipo::Bueno,
            'observaciones' => '   ',
        ]);

        $this->assertNull($revision->observaciones);
    }

    public function test_interpreta_la_fecha_de_revision_en_la_zona_de_negocio(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 18:00:00', 'America/Bogota'));

        try {
            $encargado = $this->personal();
            $prestamo = Prestamo::factory()->entregado()->create();

            $revision = $this->service->registrar($prestamo, $encargado, [
                'condicion' => CondicionEquipo::Bueno,
                'fecha_revision' => '2026-11-17 16:30:00',
            ]);

            // 16:30 Bogota (UTC-5) = 21:30 UTC
            $this->assertSame(
                '2026-11-17 21:30:00',
                $revision->fresh()->fecha_revision->format('Y-m-d H:i:s')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_usa_now_cuando_no_se_envia_fecha_de_revision(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 14:00:00', 'UTC'));

        try {
            $encargado = $this->personal();
            $prestamo = Prestamo::factory()->entregado()->create();

            $revision = $this->service->registrar($prestamo, $encargado, [
                'condicion' => CondicionEquipo::Bueno,
            ]);

            $this->assertSame(
                '2026-11-17 14:00:00',
                $revision->fresh()->fecha_revision->format('Y-m-d H:i:s')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_rechaza_una_fecha_de_revision_con_formato_invalido(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        foreach (['2026-11-17', '17/11/2026', 'mañana'] as $fecha) {
            try {
                $this->service->registrar($prestamo, $encargado, [
                    'condicion' => CondicionEquipo::Bueno,
                    'fecha_revision' => $fecha,
                ]);
                $this->fail("Se esperaba una ValidationException para la fecha {$fecha}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('fecha_revision', $e->errors());
            }
        }

        $this->assertDatabaseCount('revisiones_equipo', 0);
    }

    public function test_no_deja_rastro_si_la_validacion_falla(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->entregado()->create();

        try {
            $this->service->registrar($prestamo, $encargado, [
                'condicion' => CondicionEquipo::RequiereMantenimiento,
                // Falta observaciones: debe abortar sin insertar nada.
            ]);
        } catch (ValidationException) {
            // esperado
        }

        $this->assertDatabaseCount('revisiones_equipo', 0);
        $this->assertNull(RevisionEquipo::where('prestamo_id', $prestamo->id)->first());
    }
}
