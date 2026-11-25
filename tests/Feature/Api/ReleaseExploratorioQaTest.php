<?php

namespace Tests\Feature\Api;

use App\Enums\CondicionEquipo;
use App\Enums\EstadoPrestamo;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Equipo;
use App\Models\Incidencia;
use App\Models\Prestamo;
use App\Models\Sancion;
use App\Models\User;
use App\Support\FechaNegocio;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pruebas exploratorias propias de la validación de release develop → main
 * (25 nov 2026, QA). No repiten las pruebas del desarrollador: buscan los
 * bordes de los seis defectos corregidos (BUG-0005 … BUG-0010).
 *
 * Trazabilidad: Documentación/05 - Calidad y pruebas/Release develop-main/.
 *
 * Nota de alcance (normalización de instantes en SancionService): este archivo
 * verifica la mora por HTTP con fechas de negocio enviadas como ISO 8601, que
 * la API normaliza a UTC con FechaNegocio::parsear() — comprobado con
 * POST /api/prestamos y -05:00 (18:00 Bogotá → 23:00 UTC). Lo que NO cubre es
 * pasar un CarbonInterface crudo y en zona de Bogotá al servicio: al sembrar el
 * modelo eso se guarda sin convertir de zona. Esa normalización interna del
 * servicio se cubre en una prueba aparte, en desarrollo por otro agente y que
 * todavía no está en origin/develop; cuando llegue, es la referencia de ese
 * caso y no hace falta duplicarla aquí.
 */
class ReleaseExploratorioQaTest extends TestCase
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

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------

    private function usuario(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    private function personal(): User
    {
        return $this->usuario('encargado');
    }

    private function equipoDisponible(): Equipo
    {
        return Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
    }

    private function enBogota(int $anio, int $mes, int $dia, int $hora, int $minuto, int $segundo = 0): Carbon
    {
        return Carbon::create($anio, $mes, $dia, $hora, $minuto, $segundo, FechaNegocio::zonaNegocio());
    }

    /**
     * Instante fijo desde el que se ejecutan las pruebas de mora: 20 dic 2026
     * 07:00 en Bogotá (12:00 UTC). Determinista, nunca depende del reloj real.
     */
    private function fijarReloj(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-20 12:00:00', 'UTC'));
    }

    private function prestamoEntregadoParaMora(User $solicitante, Carbon $estimada): Prestamo
    {
        $equipo = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);

        // Al sembrar el modelo hay que entregar los instantes en UTC: Eloquent
        // formatea el Carbon tal cual (sin convertir de zona), de modo que un
        // Carbon de Bogotá se guardaba con la hora local y se releía como UTC
        // (cinco horas antes). La API no tiene ese problema porque normaliza
        // los strings con FechaNegocio::parsear(); ver la nota del docblock.
        return Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'fecha_inicio' => $this->enBogota(2026, 12, 8, 8, 0)->utc(),
            'fecha_aprobacion' => $this->enBogota(2026, 12, 7, 9, 0)->utc(),
            'fecha_entrega_real' => $this->enBogota(2026, 12, 8, 8, 0)->utc(),
            'fecha_devolucion_estimada' => $estimada->copy()->utc(),
        ]);
    }

    private function devolver(Prestamo $prestamo, Carbon $fecha): TestResponse
    {
        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/devolucion", [
                'condicion_devolucion' => CondicionEquipo::Bueno->value,
                'fecha_devolucion_real' => $fecha->toIso8601String(),
            ]);
    }

    private function prestamoRestringido(User $solicitante): Prestamo
    {
        $categoria = Categoria::factory()->create([
            'nivel_restriccion' => Categoria::REQUIERE_AUTORIZACION,
        ]);

        $equipo = Equipo::factory()->create([
            'categoria_id' => $categoria->id,
            'estado' => Equipo::ESTADO_DISPONIBLE,
        ]);

        return Prestamo::factory()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
            'estado' => EstadoPrestamo::Solicitado,
            'requiere_autorizacion' => true,
        ]);
    }

    private function prestamoAprobado(User $solicitante): array
    {
        $equipo = $this->equipoDisponible();

        $prestamo = Prestamo::factory()->aprobado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipo->id,
        ]);

        return [$prestamo, $equipo];
    }

    private function entregar(Prestamo $prestamo): TestResponse
    {
        return $this->actingAs($this->personal(), 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/entrega", [
                'condicion_entrega' => CondicionEquipo::Bueno->value,
            ]);
    }

    // ---------------------------------------------------------------------
    // BUG-0005 · una cuenta desactivada no conserva la sesión
    // ---------------------------------------------------------------------

    /**
     * TC-SRV-054 · BUG-0005 · HU-02: un admin desactivado conserva su token
     * (desactivación hecha en la base, no por la API). El middleware debe
     * responder 401 en lectura y en escritura, y borrar el token.
     */
    public function test_un_admin_desactivado_con_token_vivo_no_puede_leer_ni_cambiar_usuarios(): void
    {
        $adminDesactivado = $this->usuario('admin');
        $token = $adminDesactivado->createToken('pruebas')->plainTextToken;

        // Desactivación fuera de la API: is_active = false, token intacto.
        User::whereKey($adminDesactivado->id)->update(['is_active' => false]);
        $this->assertSame(1, $adminDesactivado->tokens()->count());

        $victima = $this->usuario('estudiante');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/usuarios')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Tu cuenta está desactivada. Contacta a un administrador.');

        // El primer 401 ya revocó el token de la petición.
        $this->assertSame(0, $adminDesactivado->tokens()->count());

        // Sin token no hay segunda petición posible: la cuenta está fuera.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/usuarios/{$victima->id}/desactivar")
            ->assertStatus(401);

        $this->assertTrue($victima->fresh()->is_active, 'La víctima no debía quedar desactivada.');
    }

    /**
     * TC-SRV-055 · BUG-0005 · HU-02: al desactivar se revocan TODAS las
     * sesiones (varios dispositivos) y al reactivar el token viejo no revive.
     */
    public function test_desactivar_revoca_todas_las_sesiones_y_el_token_viejo_no_revive(): void
    {
        $estudiante = $this->usuario('estudiante');
        $tokens = [
            $estudiante->createToken('movil')->plainTextToken,
            $estudiante->createToken('escritorio')->plainTextToken,
            $estudiante->createToken('tableta')->plainTextToken,
        ];
        $this->assertSame(3, $estudiante->tokens()->count());

        $admin = $this->usuario('admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/usuarios/{$estudiante->id}/desactivar")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $estudiante->tokens()->count());
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$tokens[0]}")
            ->getJson('/api/me')
            ->assertStatus(401);

        // Reactivar no resucita las sesiones revocadas.
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/usuarios/{$estudiante->id}/activar")
            ->assertOk();

        $this->app['auth']->forgetGuards();

        foreach ($tokens as $indice => $token) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/me')
                ->assertStatus(401);
            $this->assertSame(0, $estudiante->tokens()->count(), "El token {$indice} no debía existir.");
        }
    }

    /**
     * TC-SRV-056 · BUG-0005 · HU-01: borde del middleware. Un usuario recién
     * creado lleva is_active = null en memoria (el true vive en la columna):
     * la comparación estricta no debe rechazarlo.
     */
    public function test_el_middleware_no_rechaza_a_un_usuario_activo_recien_creado(): void
    {
        $usuario = $this->usuario('estudiante');
        $this->assertNull($usuario->is_active, 'Precondición: en memoria is_active es null.');

        // /api/me responde {user: {...}}, no {data: {...}}.
        $this->actingAs($usuario, 'sanctum')
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', $usuario->email);
    }

    // ---------------------------------------------------------------------
    // BUG-0006 · la mora se cuenta en días calendario de Bogotá
    // ---------------------------------------------------------------------

    /**
     * TC-SRV-057 · BUG-0006 · RN-04: devolver exactamente en el instante
     * estimado no es retraso: no debe nacer ninguna sanción.
     */
    public function test_devolver_exactamente_en_la_hora_estimada_no_genera_sancion(): void
    {
        $this->fijarReloj();

        $solicitante = $this->usuario('estudiante');
        $estimada = $this->enBogota(2026, 12, 10, 18, 0);
        $prestamo = $this->prestamoEntregadoParaMora($solicitante, $estimada);

        $this->devolver($prestamo, $estimada->copy())->assertOk();

        $this->assertSame(0, Sancion::where('user_id', $solicitante->id)->count());
    }

    /**
     * TC-SRV-058 · BUG-0006 · RN-04: borde de medianoche. Un segundo después
     * de las 23:59:59 de Bogotá ya es el día siguiente y cuenta un día, no
     * dos aunque en UTC sigan siendo las 05:00 del mismo día.
     */
    public function test_un_segundo_despues_de_medianoche_en_bogota_cuenta_un_dia(): void
    {
        $this->fijarReloj();

        $solicitante = $this->usuario('estudiante');
        $estimada = $this->enBogota(2026, 12, 10, 23, 59, 59);
        $prestamo = $this->prestamoEntregadoParaMora($solicitante, $estimada);

        $this->devolver($prestamo, $this->enBogota(2026, 12, 11, 0, 0, 0))->assertOk();

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();
        $this->assertSame(1, $sancion->dias_retraso);
        $this->assertSame(3, $sancion->dias_sancion);
    }

    /**
     * TC-SRV-059 · BUG-0006/RN-04: los límites de la escala por defecto en
     * días calendario de Bogotá (1–2 → 3; 3–5 → 7; 6+ → 15).
     *
     * @param  int  $diasRetraso  días calendario transcurridos desde la estimada
     * @param  int  $diasSancion  días de sanción esperados
     */
    #[DataProvider('limitesDeLaEscala')]
    public function test_la_escala_asigna_el_tramo_correcto_en_cada_limite(int $diasRetraso, int $diasSancion): void
    {
        $this->fijarReloj();

        $solicitante = $this->usuario('estudiante');
        $estimada = $this->enBogota(2026, 12, 10, 23, 59, 59);
        $prestamo = $this->prestamoEntregadoParaMora($solicitante, $estimada);

        $devolucion = $estimada->copy()->addDays($diasRetraso)->setTime(0, 30);

        // El reloj se mueve al momento de la devolución: con el instante fijo
        // del 20 dic, el tramo de 20 días cae en el futuro y el endpoint lo
        // rechaza («no puede ser posterior al momento actual»).
        Carbon::setTestNow($devolucion->copy()->addHours(10));

        $this->devolver($prestamo, $devolucion)->assertOk();

        $sancion = Sancion::where('user_id', $solicitante->id)->firstOrFail();
        $this->assertSame($diasRetraso, $sancion->dias_retraso);
        $this->assertSame($diasSancion, $sancion->dias_sancion);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function limitesDeLaEscala(): array
    {
        return [
            'un día (inicio del tramo 1–2)' => [1, 3],
            'dos días (fin del tramo 1–2)' => [2, 3],
            'tres días (inicio del tramo 3–5)' => [3, 7],
            'cinco días (fin del tramo 3–5)' => [5, 7],
            'seis días (inicio del tramo abierto)' => [6, 15],
            'veinte días (tramo abierto)' => [20, 15],
        ];
    }

    // ---------------------------------------------------------------------
    // BUG-0007 · validación de la escala de mora
    // ---------------------------------------------------------------------

    /**
     * TC-SRV-060 · BUG-0007 · RN-04: una escala válida se persiste y el
     * servicio de sanción la aplica de verdad (no basta con que el 422
     * funcione: el valor aceptado tiene que tener efecto).
     */
    public function test_la_escala_aceptada_se_persiste_y_se_aplica_a_la_sancion(): void
    {
        $this->fijarReloj();

        $admin = $this->usuario('admin');

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['mora.escala' => [
                ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 0],
                ['desde' => 3, 'hasta' => null, 'dias_sancion' => 10],
            ]]])
            ->assertOk()
            ->assertJsonPath('data.mora.escala', [
                ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 0],
                ['desde' => 3, 'hasta' => null, 'dias_sancion' => 10],
            ]);

        $conGracia = $this->usuario('estudiante');
        $prestamoGracia = $this->prestamoEntregadoParaMora(
            $conGracia,
            $this->enBogota(2026, 12, 10, 23, 59, 59)
        );
        $this->devolver($prestamoGracia, $this->enBogota(2026, 12, 12, 10, 0))->assertOk();
        $this->assertSame(0, Sancion::where('user_id', $conGracia->id)->count());

        $sancionado = $this->usuario('estudiante');
        $prestamoTarde = $this->prestamoEntregadoParaMora(
            $sancionado,
            $this->enBogota(2026, 12, 10, 23, 59, 59)
        );
        $this->devolver($prestamoTarde, $this->enBogota(2026, 12, 13, 10, 0))->assertOk();

        $sancion = Sancion::where('user_id', $sancionado->id)->firstOrFail();
        $this->assertSame(3, $sancion->dias_retraso);
        $this->assertSame(10, $sancion->dias_sancion);
    }

    /**
     * TC-SRV-061 · BUG-0007 · RN-04: dos tramos que empiezan el mismo día son
     * ambiguos y deben rechazarse.
     */
    public function test_rechaza_dos_tramos_que_empiezan_el_mismo_dia(): void
    {
        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['mora.escala' => [
                ['desde' => 1, 'hasta' => 2, 'dias_sancion' => 3],
                ['desde' => 2, 'hasta' => null, 'dias_sancion' => 7],
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    /**
     * TC-SRV-062 · BUG-0007 · RN-04: un único tramo cerrado (1–5) deja sin
     * sanción cualquier retraso mayor; debe rechazarse.
     */
    public function test_rechaza_una_escala_de_un_solo_tramo_cerrado(): void
    {
        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['mora.escala' => [
                ['desde' => 1, 'hasta' => 5, 'dias_sancion' => 3],
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    /**
     * TC-SRV-063 · BUG-0007 · RN-04: campos ausentes o de tipo incorrecto.
     *
     * @param  array<mixed>  $escala
     */
    #[DataProvider('escalasInvalidas')]
    public function test_rechaza_tramos_con_campos_ausentes_o_invalidos(array $escala): void
    {
        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['mora.escala' => $escala]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.mora.escala']);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function escalasInvalidas(): array
    {
        return [
            'sin hasta' => [[['desde' => 1, 'dias_sancion' => 3]]],
            'sin dias_sancion' => [[['desde' => 1, 'hasta' => null]]],
            'desde cero' => [[['desde' => 0, 'hasta' => null, 'dias_sancion' => 3]]],
            'desde no numérico' => [[['desde' => 'abc', 'hasta' => null, 'dias_sancion' => 3]]],
            'hasta negativo' => [[['desde' => 1, 'hasta' => -1, 'dias_sancion' => 3]]],
            'dias_sancion negativo' => [[['desde' => 1, 'hasta' => null, 'dias_sancion' => -3]]],
            'tramo que no es objeto' => [['tramo-uno']],
            'escala vacía' => [[]],
        ];
    }

    // ---------------------------------------------------------------------
    // BUG-0008 · validación de duracion.categorias
    // ---------------------------------------------------------------------

    /**
     * TC-SRV-064 · BUG-0008 · RN-06: duraciones inválidas por categoría.
     */
    #[DataProvider('duracionesInvalidas')]
    public function test_rechaza_duraciones_de_categoria_invalidas(mixed $dias): void
    {
        $categoria = Categoria::factory()->create();

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['duracion.categorias' => [
                (string) $categoria->id => $dias,
            ]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parametros.duracion.categorias']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function duracionesInvalidas(): array
    {
        return [
            'cero entero' => [0],
            'cero texto' => ['0'],
            'negativo' => [-3],
            'decimal' => [2.5],
            'texto' => ['muchos'],
        ];
    }

    /**
     * TC-SRV-065 · BUG-0008 · RN-06: la duración aceptada se persiste y
     * cambia el límite real de la solicitud (2 días: el día 2 a las 23:59
     * pasa, el día 3 a las 00:00 no).
     */
    public function test_la_duracion_aceptada_se_persiste_y_cambia_el_limite_de_la_solicitud(): void
    {
        $categoria = Categoria::factory()->create();
        $equipo = Equipo::factory()->create([
            'categoria_id' => $categoria->id,
            'estado' => Equipo::ESTADO_DISPONIBLE,
        ]);
        $estudiante = $this->usuario('estudiante');
        $inicio = now()->addDay()->setTime(8, 0);

        $this->actingAs($this->usuario('admin'), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['duracion.categorias' => [
                (string) $categoria->id => 2,
            ]]])
            ->assertOk()
            ->assertJsonPath("data.duracion.categorias.{$categoria->id}", 2);

        $payload = fn (Carbon $devolucion) => [
            'equipo_id' => $equipo->id,
            'motivo' => 'Práctica de laboratorio',
            'fecha_inicio' => $inicio->toIso8601String(),
            'fecha_devolucion_estimada' => $devolucion->toIso8601String(),
        ];

        // Un minuto después del límite: rechazado.
        $this->actingAs($estudiante, 'sanctum')
            ->postJson('/api/prestamos', $payload($inicio->copy()->addDays(3)))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_devolucion_estimada']);

        $this->assertSame(0, Prestamo::count());

        // Justo en el límite: aceptado.
        $this->actingAs($estudiante, 'sanctum')
            ->postJson('/api/prestamos', $payload($inicio->copy()->addDays(2)->endOfDay()))
            ->assertStatus(201);

        $this->assertSame(1, Prestamo::count());
    }

    // ---------------------------------------------------------------------
    // BUG-0009 · control de dos personas en solicitudes restringidas
    // ---------------------------------------------------------------------

    /**
     * TC-SRV-066 · BUG-0009 · RN-03: un admin que solicitó un equipo
     * restringido no puede autorizarlo ni aprobarlo; el intento fallido no
     * deja marca en el préstamo.
     */
    public function test_un_admin_solicitante_no_puede_autorizar_ni_aprobar_su_solicitud_restringida(): void
    {
        $adminSolicitante = $this->usuario('admin');
        $prestamo = $this->prestamoRestringido($adminSolicitante);

        $this->actingAs($adminSolicitante, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['prestamo']);

        $fresco = $prestamo->fresh();
        $this->assertNull($fresco->autorizado_por, 'La autorización fallida no debía dejar firma.');
        $this->assertNull($fresco->fecha_autorizacion);

        $this->actingAs($adminSolicitante, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['prestamo']);

        $this->assertSame(EstadoPrestamo::Solicitado, $prestamo->fresh()->estado);
    }

    /**
     * TC-SRV-067 · BUG-0009 · RN-03: el camino válido de dos personas. El
     * encargado (distinto del solicitante) autoriza y luego aprueba la
     * solicitud restringida del estudiante.
     */
    public function test_el_encargado_autoriza_y_aprueba_la_solicitud_restringida_de_otro(): void
    {
        $estudiante = $this->usuario('estudiante');
        $prestamo = $this->prestamoRestringido($estudiante);
        $encargado = $this->personal();

        // autorizado_por es la columna (id entero), no un objeto anidado.
        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/autorizacion")
            ->assertOk()
            ->assertJsonPath('data.autorizado', true)
            ->assertJsonPath('data.autorizado_por', $encargado->id);

        $this->assertSame($encargado->id, $prestamo->fresh()->autorizado_por);

        $this->actingAs($encargado, 'sanctum')
            ->postJson("/api/prestamos/{$prestamo->id}/aprobacion")
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoPrestamo::Aprobado->value);

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
    }

    // ---------------------------------------------------------------------
    // BUG-0010 · la entrega revalida la elegibilidad
    // ---------------------------------------------------------------------

    /**
     * TC-SRV-068 · BUG-0010 · RN-01: una sanción ya vencida no debe frenar la
     * entrega (borde contrario: evitar bloquear de más). Al consultarla queda
     * marcada como cumplida.
     */
    public function test_una_sancion_ya_vencida_no_bloquea_la_entrega(): void
    {
        $solicitante = $this->usuario('estudiante');
        [$prestamo, $equipo] = $this->prestamoAprobado($solicitante);

        $sancion = Sancion::create([
            'user_id' => $solicitante->id,
            'prestamo_id' => Prestamo::factory()->devuelto()->create(['usuario_id' => $solicitante->id])->id,
            'motivo' => Sancion::MOTIVO_MORA,
            'dias_retraso' => 1,
            'dias_sancion' => 3,
            'fecha_inicio' => now()->subDays(5),
            'fecha_fin' => now()->subDay(),
            'estado' => Sancion::ESTADO_ACTIVA,
        ]);

        $this->entregar($prestamo)
            ->assertOk()
            ->assertJsonPath('data.estado', EstadoPrestamo::Entregado->value);

        $this->assertSame(EstadoPrestamo::Entregado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_EN_PRESTAMO, $equipo->fresh()->estado);
        $this->assertSame(Sancion::ESTADO_CUMPLIDA, $sancion->fresh()->estado);
    }

    /**
     * TC-SRV-069 · BUG-0010 · RN-01/RN-04: un préstamo entregado y vencido
     * que aparece entre la aprobación y la entrega también bloquea, no solo
     * las sanciones.
     */
    public function test_un_prestamo_vencido_aparecido_tras_la_aprobacion_bloquea_la_entrega(): void
    {
        $solicitante = $this->usuario('estudiante');
        [$prestamo, $equipo] = $this->prestamoAprobado($solicitante);

        $equipoVencido = Equipo::factory()->create(['estado' => Equipo::ESTADO_EN_PRESTAMO]);
        Prestamo::factory()->entregado()->create([
            'usuario_id' => $solicitante->id,
            'equipo_id' => $equipoVencido->id,
            'fecha_devolucion_estimada' => now()->subDays(3),
        ]);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        // El mensaje depende de mora.dias_bloqueo (por defecto 1): «…con 1 día(s)
        // o más de retraso», no la palabra «vencido».
        $this->assertStringContainsString('1 día(s) o más de retraso', $response->json('errors.usuario_id.0'));

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    /**
     * TC-SRV-070 · BUG-0010 · RN-01: si la capacitación pasa a ser exigible
     * después de aprobar, el solicitante no capacitado no recibe el equipo.
     */
    public function test_la_capacitacion_exigida_despues_de_aprobar_bloquea_la_entrega(): void
    {
        $solicitante = $this->usuario('estudiante');
        User::whereKey($solicitante->id)->update(['capacitado' => false]);
        [$prestamo, $equipo] = $this->prestamoAprobado($solicitante);

        Configuracion::establecer('elegibilidad.requiere_capacitacion', true);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('capacitado', $response->json('errors.usuario_id.0'));

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }

    /**
     * TC-SRV-071 · BUG-0010 · RN-08: un equipo que pasó a mantenimiento
     * después de la aprobación no puede entregarse.
     */
    public function test_un_equipo_en_mantenimiento_tras_la_aprobacion_bloquea_la_entrega(): void
    {
        $solicitante = $this->usuario('estudiante');
        [$prestamo, $equipo] = $this->prestamoAprobado($solicitante);

        $equipo->update(['estado' => Equipo::ESTADO_MANTENIMIENTO]);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['equipo']);
        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_MANTENIMIENTO, $equipo->fresh()->estado);
    }

    /**
     * TC-SRV-072 · BUG-0010 · RN-08: una incidencia abierta que aparece tras
     * la aprobación bloquea la entrega y no deja estados a medias.
     */
    public function test_una_incidencia_aparecida_tras_la_aprobacion_bloquea_la_entrega(): void
    {
        $solicitante = $this->usuario('estudiante');
        [$prestamo, $equipo] = $this->prestamoAprobado($solicitante);

        $anterior = Prestamo::factory()->devuelto()->create(['usuario_id' => $solicitante->id]);

        Incidencia::create([
            'prestamo_id' => $anterior->id,
            'equipo_id' => $anterior->equipo_id,
            'user_id' => $solicitante->id,
            'descripcion' => 'Equipo devuelto con la pantalla rayada.',
            'estado' => Incidencia::ESTADO_ABIERTA,
            'restringe_hasta' => now()->addDays(7),
        ]);

        $response = $this->entregar($prestamo);

        $response->assertStatus(422)->assertJsonValidationErrors(['usuario_id']);
        $this->assertStringContainsString('incidencia', $response->json('errors.usuario_id.0'));

        $this->assertSame(EstadoPrestamo::Aprobado, $prestamo->fresh()->estado);
        $this->assertSame(Equipo::ESTADO_DISPONIBLE, $equipo->fresh()->estado);
    }
}
