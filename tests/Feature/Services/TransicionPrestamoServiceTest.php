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
        $this->assertNotNull($prestamoActualizado->fecha_entrega_real);
        $this->assertSame('Entrega: Todo en orden al entregar', $prestamoActualizado->observaciones);

        $this->assertDatabaseHas('prestamos', [
            'id' => $prestamo->id,
            'estado' => EstadoPrestamo::Entregado->value,
            'entregado_por' => $personal->id,
            'condicion_entrega' => CondicionEquipo::Bueno->value,
            'observaciones' => 'Entrega: Todo en orden al entregar',
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
}
