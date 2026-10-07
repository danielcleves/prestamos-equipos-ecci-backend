<?php

namespace Tests\Feature\Services;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Prestamo;
use App\Models\User;
use App\Services\TransicionPrestamoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class TransicionPrestamoServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransicionPrestamoService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->servicio = new TransicionPrestamoService;
    }

    public function test_transicionar_a_aprobado_actualiza_estado_fecha_y_gestionado_por(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();
        $personal = User::factory()->create();

        $prestamoActualizado = $this->servicio->transicionar($prestamo, EstadoPrestamo::Aprobado, [
            'actor' => $personal,
        ]);

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamoActualizado->estado);
        $this->assertSame($personal->id, $prestamoActualizado->gestionado_por);
        $this->assertNotNull($prestamoActualizado->fecha_aprobacion);
    }

    public function test_aprobar_sin_actor_lanza_invalid_argument_exception(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El actor que aprueba la solicitud es obligatorio.');

        $this->servicio->transicionar($prestamo, EstadoPrestamo::Aprobado);
    }

    public function test_transicionar_a_rechazado_actualiza_estado_fecha_gestionado_por_y_motivo(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();
        $personal = User::factory()->create();

        $prestamoActualizado = $this->servicio->transicionar($prestamo, EstadoPrestamo::Rechazado, [
            'actor' => $personal,
            'motivo' => 'Equipo requerido para mantenimiento preventivo.',
        ]);

        $this->assertSame(EstadoPrestamo::Rechazado, $prestamoActualizado->estado);
        $this->assertSame($personal->id, $prestamoActualizado->gestionado_por);
        $this->assertSame('Equipo requerido para mantenimiento preventivo.', $prestamoActualizado->motivo_rechazo);
        $this->assertNotNull($prestamoActualizado->fecha_rechazo);
    }

    public function test_rechazar_almacena_motivo_recortado_sin_espacios_al_inicio_ni_al_final(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();
        $personal = User::factory()->create();

        $prestamoActualizado = $this->servicio->transicionar($prestamo, EstadoPrestamo::Rechazado, [
            'actor' => $personal,
            'motivo' => "   Motivo con espacios alrededor   \n",
        ]);

        $this->assertSame('Motivo con espacios alrededor', $prestamoActualizado->motivo_rechazo);
        $this->assertSame('Motivo con espacios alrededor', Prestamo::findOrFail($prestamo->id)->motivo_rechazo);
    }

    public function test_rechazar_sin_actor_lanza_invalid_argument_exception(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El actor que rechaza la solicitud es obligatorio.');

        $this->servicio->transicionar($prestamo, EstadoPrestamo::Rechazado, [
            'motivo' => 'Motivo válido',
        ]);
    }

    public function test_rechazar_sin_motivo_o_vacio_lanza_invalid_argument_exception(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();
        $personal = User::factory()->create();

        try {
            $this->servicio->transicionar($prestamo, EstadoPrestamo::Rechazado, [
                'actor' => $personal,
            ]);
            $this->fail('Se esperaba InvalidArgumentException al faltar el motivo.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('El motivo del rechazo es obligatorio.', $e->getMessage());
        }

        try {
            $this->servicio->transicionar($prestamo, EstadoPrestamo::Rechazado, [
                'actor' => $personal,
                'motivo' => '   ',
            ]);
            $this->fail('Se esperaba InvalidArgumentException por motivo con solo espacios.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('El motivo del rechazo es obligatorio.', $e->getMessage());
        }
    }

    public function test_transicion_valida_actualiza_estado_y_columnas_correspondientes(): void
    {
        $prestamo = Prestamo::factory()->aprobado()->create();
        $personal = User::factory()->create();

        $prestamoActualizado = $this->servicio->transicionar($prestamo, EstadoPrestamo::Entregado, [
            'actor' => $personal,
            'condicion' => CondicionEquipo::Bueno,
            'observaciones' => 'Todo en orden al entregar',
        ]);

        $this->assertSame(EstadoPrestamo::Entregado, $prestamoActualizado->estado);
        $this->assertSame($personal->id, $prestamoActualizado->entregado_por);
        $this->assertSame(CondicionEquipo::Bueno, $prestamoActualizado->condicion_entrega);
        $this->assertSame('Todo en orden al entregar', $prestamoActualizado->observaciones_entrega);
        $this->assertNull($prestamoActualizado->observaciones_devolucion);

        $this->assertDatabaseHas('prestamos', [
            'id' => $prestamo->id,
            'estado' => EstadoPrestamo::Entregado->value,
            'entregado_por' => $personal->id,
            'condicion_entrega' => CondicionEquipo::Bueno->value,
            'observaciones_entrega' => 'Todo en orden al entregar',
            'observaciones_devolucion' => null,
        ]);
    }

    public function test_transicion_invalida_lanza_validation_exception_con_errors_estado_y_no_modifica_datos(): void
    {
        $prestamo = Prestamo::factory()->solicitado()->create();

        try {
            $this->servicio->transicionar($prestamo, EstadoPrestamo::Entregado, [
                'actor' => User::factory()->create(),
            ]);
            $this->fail('Se esperaba ValidationException por transición inválida.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('estado', $e->errors());
            $this->assertSame(
                'El préstamo está en estado Solicitado y no puede pasar a Entregado.',
                $e->errors()['estado'][0]
            );
        }

        $prestamoEnBd = Prestamo::findOrFail($prestamo->id);
        $this->assertSame(EstadoPrestamo::Solicitado, $prestamoEnBd->estado);
        $this->assertNull($prestamoEnBd->fecha_entrega_real);
    }

    public function test_entregar_sin_actor_lanza_invalid_argument_exception(): void
    {
        $prestamo = Prestamo::factory()->aprobado()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El actor que entrega el equipo es obligatorio.');

        $this->servicio->transicionar($prestamo, EstadoPrestamo::Entregado, [
            'condicion' => CondicionEquipo::Bueno,
        ]);
    }

    public function test_devolver_sin_actor_o_sin_condicion_lanza_invalid_argument_exception(): void
    {
        $prestamo = Prestamo::factory()->entregado()->create();
        $personal = User::factory()->create();

        // Sin actor
        try {
            $this->servicio->transicionar($prestamo, EstadoPrestamo::Devuelto, [
                'condicion' => CondicionEquipo::Bueno,
            ]);
            $this->fail('Se esperaba InvalidArgumentException al faltar el actor.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('El actor que recibe el equipo es obligatorio.', $e->getMessage());
        }

        // Sin condición
        try {
            $this->servicio->transicionar($prestamo, EstadoPrestamo::Devuelto, [
                'actor' => $personal,
            ]);
            $this->fail('Se esperaba InvalidArgumentException al faltar la condición.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('La condición del equipo en la devolución es obligatoria.', $e->getMessage());
        }
    }

    public function test_valida_contra_el_estado_fresco_en_base_de_datos_evitando_carreras(): void
    {
        $prestamo = Prestamo::factory()->aprobado()->create();
        $personal = User::factory()->create();

        // Simula que otra petición concurrente cambió el estado en BD a 'cancelado'
        DB::table('prestamos')->where('id', $prestamo->id)->update([
            'estado' => EstadoPrestamo::Cancelado->value,
        ]);

        // La instancia en memoria aún dice 'aprobado', pero el servicio debe recargarla fresca con bloqueo
        try {
            $this->servicio->transicionar($prestamo, EstadoPrestamo::Entregado, [
                'actor' => $personal,
                'condicion' => CondicionEquipo::Bueno,
            ]);
            $this->fail('Se esperaba ValidationException al evaluar el estado real en BD.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('estado', $e->errors());
            $this->assertSame(
                'El préstamo está en estado Cancelado y no puede pasar a Entregado.',
                $e->errors()['estado'][0]
            );
        }
    }

    public function test_transicion_devolucion_establece_observaciones_devolucion_sin_modificar_observaciones_entrega(): void
    {
        $personal = User::factory()->create();
        $prestamo = Prestamo::factory()->entregado()->create([
            'observaciones_entrega' => 'Entregado con maletín',
            'observaciones_devolucion' => null,
        ]);

        $prestamoActualizado = $this->servicio->transicionar($prestamo, EstadoPrestamo::Devuelto, [
            'actor' => $personal,
            'condicion' => CondicionEquipo::Bueno,
            'observaciones' => 'Devuelto limpio y completo',
        ]);

        $this->assertSame(EstadoPrestamo::Devuelto, $prestamoActualizado->estado);
        $this->assertSame('Entregado con maletín', $prestamoActualizado->observaciones_entrega);
        $this->assertSame('Devuelto limpio y completo', $prestamoActualizado->observaciones_devolucion);
    }
}
