<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContratoPrestamoResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_todas_las_respuestas_de_prestamo_tienen_mismo_conjunto_de_claves_para_rol_encargado(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $solicitante = User::factory()->create();
        $solicitante->assignRole('usuario');

        $equipo1 = Equipo::factory()->create();
        $equipo2 = Equipo::factory()->create();

        // 1. store (POST /api/prestamos) ejecutado por el personal o usuario
        $resStore = $this->actingAs($encargado, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo1->id,
            'motivo' => 'Uso para auditoría',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);
        $resStore->assertStatus(201);
        $prestamoId = $resStore->json('data.id');

        // 2. show (GET /api/prestamos/{id})
        $resShow = $this->actingAs($encargado, 'sanctum')->getJson("/api/prestamos/{$prestamoId}");
        $resShow->assertStatus(200);

        // 3. aprobacion (POST /api/prestamos/{id}/aprobacion)
        $resAprobacion = $this->actingAs($encargado, 'sanctum')->postJson("/api/prestamos/{$prestamoId}/aprobacion");
        $resAprobacion->assertStatus(200);

        // 4. rechazo (POST /api/prestamos/{id}/rechazo) sobre un segundo préstamo
        $resStore2 = $this->actingAs($encargado, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo2->id,
            'motivo' => 'Uso para prueba de rechazo',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);
        $resStore2->assertStatus(201);
        $prestamo2Id = $resStore2->json('data.id');

        $resRechazo = $this->actingAs($encargado, 'sanctum')->postJson("/api/prestamos/{$prestamo2Id}/rechazo", [
            'motivo' => 'Mantenimiento del aula',
        ]);
        $resRechazo->assertStatus(200);

        // 5. entrega (POST /api/prestamos/{id}/entrega) sobre prestamo 1
        $resEntrega = $this->actingAs($encargado, 'sanctum')->postJson("/api/prestamos/{$prestamoId}/entrega", [
            'condicion_entrega' => 'bueno',
            'observaciones' => 'Entregado con accesorios completos',
        ]);
        $resEntrega->assertStatus(200);

        // 6. activos (GET /api/prestamos/activos) mientras está entregado
        $resActivos = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/activos');
        $resActivos->assertStatus(200);
        $this->assertNotEmpty($resActivos->json('data'));

        // 7. devolucion (POST /api/prestamos/{id}/devolucion)
        $resDevolucion = $this->actingAs($encargado, 'sanctum')->postJson("/api/prestamos/{$prestamoId}/devolucion", [
            'condicion_devolucion' => 'bueno',
        ]);
        $resDevolucion->assertStatus(200);

        // 8. index (GET /api/prestamos)
        $resIndex = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos');
        $resIndex->assertStatus(200);
        $this->assertNotEmpty($resIndex->json('data'));

        // Extracción de claves ordenadas de cada respuesta
        $obtenerClaves = function (array $data): array {
            $keys = array_keys($data);
            sort($keys);

            return $keys;
        };

        $clavesEsperadas = $obtenerClaves($resShow->json('data'));

        $this->assertSame($clavesEsperadas, $obtenerClaves($resStore->json('data')), 'store no coincide en claves');
        $this->assertSame($clavesEsperadas, $obtenerClaves($resAprobacion->json('data')), 'aprobacion no coincide en claves');
        $this->assertSame($clavesEsperadas, $obtenerClaves($resRechazo->json('data')), 'rechazo no coincide en claves');
        $this->assertSame($clavesEsperadas, $obtenerClaves($resEntrega->json('data')), 'entrega no coincide en claves');
        $this->assertSame($clavesEsperadas, $obtenerClaves($resDevolucion->json('data')), 'devolucion no coincide en claves');

        foreach ($resIndex->json('data') as $index => $item) {
            $this->assertSame($clavesEsperadas, $obtenerClaves($item), "index item {$index} no coincide en claves");
        }

        foreach ($resActivos->json('data') as $index => $item) {
            $this->assertSame($clavesEsperadas, $obtenerClaves($item), "activos item {$index} no coincide en claves");
        }

        // Verificación de presencia explícita de claves sensibles a relaciones opcionales
        $clavesObligatorias = [
            'usuario_gestion',
            'usuario_entrega',
            'usuario_recepcion',
            'observaciones_entrega',
            'observaciones_devolucion',
        ];
        foreach ($clavesObligatorias as $clave) {
            $this->assertArrayHasKey($clave, $resStore->json('data'));
            $this->assertArrayHasKey($clave, $resEntrega->json('data'));
            $this->assertArrayHasKey($clave, $resDevolucion->json('data'));
        }
    }

    public function test_todas_las_respuestas_de_prestamo_tienen_mismo_conjunto_de_claves_para_rol_usuario(): void
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('usuario');

        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $equipo = Equipo::factory()->create();

        // 1. store (POST /api/prestamos)
        $resStore = $this->actingAs($usuario, 'sanctum')->postJson('/api/prestamos', [
            'equipo_id' => $equipo->id,
            'motivo' => 'Práctica universitaria',
            'fecha_inicio' => now()->addHour()->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);
        $resStore->assertStatus(201);
        $prestamoId = $resStore->json('data.id');

        // 2. show (GET /api/prestamos/{id}) en estado solicitado
        $resShowSolicitado = $this->actingAs($usuario, 'sanctum')->getJson("/api/prestamos/{$prestamoId}");
        $resShowSolicitado->assertStatus(200);

        // Creamos además un préstamo completamente devuelto para el mismo usuario
        $prestamoDevuelto = Prestamo::factory()->devuelto()->create([
            'usuario_id' => $usuario->id,
            'gestionado_por' => $encargado->id,
            'entregado_por' => $encargado->id,
            'recibido_por' => $encargado->id,
            'observaciones_entrega' => 'Observacion secreta',
            'observaciones_devolucion' => 'Otra observacion secreta',
        ]);

        // 3. show en estado devuelto
        $resShowDevuelto = $this->actingAs($usuario, 'sanctum')->getJson("/api/prestamos/{$prestamoDevuelto->id}");
        $resShowDevuelto->assertStatus(200);

        // 4. index (GET /api/prestamos)
        $resIndex = $this->actingAs($usuario, 'sanctum')->getJson('/api/prestamos');
        $resIndex->assertStatus(200);
        $this->assertNotEmpty($resIndex->json('data'));

        $obtenerClaves = function (array $data): array {
            $keys = array_keys($data);
            sort($keys);

            return $keys;
        };

        $clavesEsperadas = $obtenerClaves($resShowSolicitado->json('data'));

        $this->assertSame($clavesEsperadas, $obtenerClaves($resStore->json('data')));
        $this->assertSame($clavesEsperadas, $obtenerClaves($resShowDevuelto->json('data')));

        foreach ($resIndex->json('data') as $index => $item) {
            $this->assertSame($clavesEsperadas, $obtenerClaves($item), "index usuario item {$index} no coincide en claves");
        }

        // Para rol usuario, usuario_gestion, usuario_entrega y usuario_recepcion deben existir
        $this->assertArrayHasKey('usuario_gestion', $resStore->json('data'));
        $this->assertNull($resStore->json('data.usuario_gestion'));

        $this->assertArrayHasKey('usuario_gestion', $resShowDevuelto->json('data'));
        $this->assertNotNull($resShowDevuelto->json('data.usuario_gestion'));

        // Para rol usuario, las observaciones NUNCA deben estar expuestas
        $this->assertArrayNotHasKey('observaciones_entrega', $resStore->json('data'));
        $this->assertArrayNotHasKey('observaciones_devolucion', $resStore->json('data'));
        $this->assertArrayNotHasKey('observaciones_entrega', $resShowDevuelto->json('data'));
        $this->assertArrayNotHasKey('observaciones_devolucion', $resShowDevuelto->json('data'));
    }

    public function test_index_y_activos_usan_eager_loading_y_no_disparan_consultas_por_fila(): void
    {
        $encargado = User::factory()->create();
        $encargado->assignRole('encargado');

        $solicitante = User::factory()->create();
        $solicitante->assignRole('usuario');

        // Creamos 10 préstamos con relaciones completas
        for ($i = 0; $i < 10; $i++) {
            $equipo = Equipo::factory()->create();
            Prestamo::create([
                'usuario_id' => $solicitante->id,
                'equipo_id' => $equipo->id,
                'estado' => EstadoPrestamo::Entregado,
                'motivo' => "Motivo {$i}",
                'fecha_solicitud' => now(),
                'fecha_inicio' => now()->addHour(),
                'fecha_devolucion_estimada' => now()->addDays(2),
                'fecha_aprobacion' => now(),
                'fecha_entrega_real' => now(),
                'gestionado_por' => $encargado->id,
                'entregado_por' => $encargado->id,
                'condicion_entrega' => CondicionEquipo::Bueno,
            ]);
        }

        // Medir número de consultas en index
        $consultasIndex = 0;
        DB::listen(function () use (&$consultasIndex) {
            $consultasIndex++;
        });

        $resIndex = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos?per_page=15');
        $resIndex->assertStatus(200);
        $this->assertCount(10, $resIndex->json('data'));

        // Con 10 préstamos y eager loading estricto, las consultas deben ser constantes e independientes del número de filas:
        // 1 (conteo total paginación) + 1 (prestamos) + 1 (equipos) + 1 (categorias) + 1 (solicitante) + 1 (gestionadoPor) + 1 (entregadoPor) + 1 (recibidoPor) = 8 consultas.
        // Si hubiera N+1, dispararía al menos 10 * 5 = 50 consultas.
        $this->assertLessThanOrEqual(9, $consultasIndex, "GET /api/prestamos ejecutó {$consultasIndex} consultas (posible N+1).");

        // Medir número de consultas en activos
        $consultasActivos = 0;
        DB::listen(function () use (&$consultasActivos) {
            $consultasActivos++;
        });

        $resActivos = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/activos?per_page=15');
        $resActivos->assertStatus(200);
        $this->assertCount(10, $resActivos->json('data'));

        $this->assertLessThanOrEqual(9, $consultasActivos, "GET /api/prestamos/activos ejecutó {$consultasActivos} consultas (posible N+1).");
    }
}
