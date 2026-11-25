<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HU-07 / KAN-105: cada solicitud pendiente trae los datos del solicitante y
 * del equipo, sin filtrar credenciales ni disparar consultas por fila.
 */
class DatosSolicitudPendienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function personal(string $rol = 'encargado'): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function solicitante(): User
    {
        $user = User::factory()->create();
        $user->assignRole('estudiante');

        return $user;
    }

    // ---------------------------------------------------------------
    // ---------------------------------------------------------------
    // KAN-105: datos del solicitante y del equipo
    // ---------------------------------------------------------------

    public function test_cada_solicitud_trae_solicitante_equipo_fechas_y_estado(): void
    {
        $encargado = $this->personal();
        $solicitante = User::factory()->create([
            'name' => 'Carlos Mendoza',
            'email' => 'carlos@ecci.edu.co',
        ]);
        $solicitante->assignRole('estudiante');

        $equipo = Equipo::factory()->create([
            'codigo' => 'PORT-050',
            'nombre' => 'Laptop Dell Latitude',
        ]);

        $prestamo = Prestamo::factory()->solicitado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
        ]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes');

        $response->assertStatus(200)
            // Solicitante
            ->assertJsonPath('data.0.usuario_id', $solicitante->id)
            ->assertJsonPath('data.0.solicitante.id', $solicitante->id)
            ->assertJsonPath('data.0.solicitante.name', 'Carlos Mendoza')
            ->assertJsonPath('data.0.solicitante.email', 'carlos@ecci.edu.co')
            // Equipo
            ->assertJsonPath('data.0.equipo_id', $equipo->id)
            ->assertJsonPath('data.0.equipo.id', $equipo->id)
            ->assertJsonPath('data.0.equipo.codigo', 'PORT-050')
            ->assertJsonPath('data.0.equipo.nombre', 'Laptop Dell Latitude')
            // Estado
            ->assertJsonPath('data.0.estado', EstadoPrestamo::Solicitado->value);

        // Fechas presentes y en la zona de negocio (-05:00)
        $item = $response->json('data.0');
        foreach (['fecha_solicitud', 'fecha_inicio', 'fecha_devolucion_estimada'] as $campo) {
            $this->assertArrayHasKey($campo, $item, "falta {$campo}");
            $this->assertNotNull($item[$campo], "{$campo} llegó nulo");
            $this->assertStringEndsWith('-05:00', $item[$campo], "{$campo} no está en la zona de negocio");
        }

        $this->assertSame(
            $prestamo->fecha_devolucion_estimada->copy()->setTimezone('America/Bogota')->toIso8601String(),
            $item['fecha_devolucion_estimada']
        );
    }

    public function test_nunca_expone_password_remember_token_ni_tokens(): void
    {
        $encargado = $this->personal();
        $solicitante = $this->solicitante();
        $solicitante->forceFill(['remember_token' => 'token-secreto-recordar'])->save();
        $solicitante->createToken('dispositivo-del-solicitante');

        Prestamo::factory()->solicitado()->create(['usuario_id' => $solicitante->id]);

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes');

        $response->assertStatus(200);

        // Ni en el bloque del solicitante...
        $solicitanteJson = $response->json('data.0.solicitante');
        foreach (['password', 'remember_token', 'tokens', 'personal_access_tokens'] as $prohibido) {
            $this->assertArrayNotHasKey($prohibido, $solicitanteJson, "se expuso {$prohibido}");
        }

        // ...ni en ninguna parte del cuerpo de la respuesta.
        $cuerpo = $response->getContent();
        $this->assertStringNotContainsString('password', $cuerpo);
        $this->assertStringNotContainsString('remember_token', $cuerpo);
        $this->assertStringNotContainsString('token-secreto-recordar', $cuerpo);
        $this->assertStringNotContainsString($solicitante->getAuthPassword(), $cuerpo);
    }

    public function test_no_dispara_consultas_por_fila(): void
    {
        $encargado = $this->personal();

        // 10 solicitudes, cada una con su propio solicitante y equipo
        for ($i = 0; $i < 10; $i++) {
            $usuario = User::factory()->create();
            $usuario->assignRole('estudiante');
            Prestamo::factory()->solicitado()->create([
                'usuario_id' => $usuario->id,
                'equipo_id' => Equipo::factory()->create()->id,
            ]);
        }

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $response = $this->actingAs($encargado, 'sanctum')
            ->getJson('/api/prestamos/pendientes?per_page=15');

        $response->assertStatus(200)->assertJsonCount(10, 'data');

        // Con eager loading estricto las consultas son constantes e
        // independientes del número de filas. Mismo presupuesto que index():
        // token + conteo + prestamos + equipos + categorias + solicitante
        // + gestionadoPor + entregadoPor + recibidoPor + revision = 10.
        // Si hubiera N+1, con 10 filas serían al menos 60.
        $this->assertLessThanOrEqual(10, $consultas, "GET /api/prestamos/pendientes ejecutó {$consultas} consultas (posible N+1).");
    }

    public function test_el_contrato_coincide_con_el_de_los_demas_listados(): void
    {
        $encargado = $this->personal();
        $prestamo = Prestamo::factory()->solicitado()->create();

        $pendientes = $this->actingAs($encargado, 'sanctum')->getJson('/api/prestamos/pendientes');
        $show = $this->actingAs($encargado, 'sanctum')->getJson("/api/prestamos/{$prestamo->id}");

        $pendientes->assertStatus(200);
        $show->assertStatus(200);

        $claves = function (array $data): array {
            $keys = array_keys($data);
            sort($keys);

            return $keys;
        };

        $this->assertSame(
            $claves($show->json('data')),
            $claves($pendientes->json('data.0')),
            'pendientes no coincide en claves con el detalle'
        );
    }
}
