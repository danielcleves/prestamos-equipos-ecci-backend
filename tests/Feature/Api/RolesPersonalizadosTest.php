<?php

namespace Tests\Feature\Api;

use App\Enums\EstadoPrestamo;
use App\Models\Categoria;
use App\Models\Configuracion;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ConfiguraParametros;
use Tests\TestCase;

/**
 * RN-02 (rediseño) — Roles personalizados con su propio cupo de préstamos
 * vivos. Cubre el CRUD /api/roles, los permisos, la marca de rol
 * predeterminado, la clave virtual cupos.roles de /api/configuracion, el
 * auto-registro, el alta/edición de usuarios y el efecto real sobre la
 * solicitud de préstamos (RN-02) y la duración máxima (RN-06).
 *
 * Casos TC-ROL-001 … TC-ROL-045.
 */
class RolesPersonalizadosTest extends TestCase
{
    use ConfiguraParametros;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function usuarioConRol(?string $rol): User
    {
        $user = User::factory()->create();

        if ($rol !== null) {
            $user->assignRole($rol);
        }

        return $user;
    }

    private function admin(): User
    {
        return $this->usuarioConRol('admin');
    }

    /**
     * Rol de solicitante creado directamente en la tabla (sin pasar por la API).
     */
    private function rol(string $nombre, int $cupo, bool $predeterminado = false): Rol
    {
        return Rol::create([
            'name' => $nombre,
            'guard_name' => 'web',
            'etiqueta' => ucfirst($nombre),
            'cupo_prestamos' => $cupo,
            'es_predeterminado' => $predeterminado,
        ]);
    }

    private function prestamosVivos(User $user, int $cantidad): void
    {
        Prestamo::factory()->count($cantidad)->create([
            'usuario_id' => $user->id,
            'estado' => EstadoPrestamo::Solicitado,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function solicitud(?Equipo $equipo = null, int $dias = 2): array
    {
        $equipo ??= Equipo::factory()->create(['estado' => Equipo::ESTADO_DISPONIBLE]);
        $inicio = now()->addDay()->setTime(8, 0, 0);

        return [
            'equipo_id' => $equipo->id,
            'motivo' => 'Solicitud para probar el cupo por rol',
            'fecha_inicio' => $inicio->format('Y-m-d H:i:s'),
            'fecha_devolucion_estimada' => $inicio->copy()->addDays($dias)->setTime(18, 0, 0)->format('Y-m-d H:i:s'),
        ];
    }

    private function solicitar(User $user, ?array $payload = null)
    {
        return $this->actingAs($user->fresh(), 'sanctum')
            ->postJson('/api/prestamos', $payload ?? $this->solicitud());
    }

    // ---------------------------------------------------------------------
    // CRUD /api/roles — casos válidos
    // ---------------------------------------------------------------------

    /** TC-ROL-001 / RN-02: el admin lista los roles iniciales con su perfil. */
    public function test_tc_rol_001_el_admin_lista_los_roles_iniciales_con_cupo_y_marcas(): void
    {
        $this->usuarioConRol('estudiante');

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/roles')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'nombre', 'etiqueta', 'descripcion', 'cupo_prestamos', 'es_sistema', 'es_predeterminado', 'usuarios_count']]]);

        $roles = collect($response->json('data'))->keyBy('nombre');

        $this->assertSame(['admin', 'encargado', 'estudiante'], $roles->keys()->sort()->values()->all());
        $this->assertTrue($roles['admin']['es_sistema']);
        $this->assertTrue($roles['encargado']['es_sistema']);
        $this->assertFalse($roles['estudiante']['es_sistema']);
        $this->assertTrue($roles['estudiante']['es_predeterminado']);
        $this->assertSame(3, $roles['estudiante']['cupo_prestamos']);
        $this->assertSame(1, $roles['estudiante']['usuarios_count']);
    }

    /** TC-ROL-002 / RN-02: crear un rol deriva el nombre técnico y persiste el cupo. */
    public function test_tc_rol_002_crear_un_rol_profesor_persiste_nombre_etiqueta_y_cupo(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/roles', [
                'etiqueta' => 'Profesor',
                'descripcion' => 'Docentes de planta y cátedra',
                'cupo_prestamos' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'profesor')
            ->assertJsonPath('data.etiqueta', 'Profesor')
            ->assertJsonPath('data.cupo_prestamos', 5)
            ->assertJsonPath('data.es_sistema', false)
            ->assertJsonPath('data.es_predeterminado', false)
            ->assertJsonPath('data.usuarios_count', 0);

        $this->assertDatabaseHas('roles', [
            'name' => 'profesor',
            'guard_name' => 'web',
            'etiqueta' => 'Profesor',
            'descripcion' => 'Docentes de planta y cátedra',
            'cupo_prestamos' => 5,
            'es_predeterminado' => false,
        ]);
    }

    /** TC-ROL-003 / RN-02: etiqueta con tildes y espacios → slug con guion bajo; espacios normalizados. */
    public function test_tc_rol_003_la_etiqueta_con_tildes_y_espacios_genera_un_slug_con_guion_bajo(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/roles', [
                'etiqueta' => '   Coordinador    académico  ',
                'cupo_prestamos' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'coordinador_academico')
            ->assertJsonPath('data.etiqueta', 'Coordinador académico');
    }

    /** TC-ROL-004 / RN-02: consultar un rol por id. */
    public function test_tc_rol_004_el_admin_consulta_el_detalle_de_un_rol(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/roles/{$profesor->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $profesor->id)
            ->assertJsonPath('data.nombre', 'profesor')
            ->assertJsonPath('data.cupo_prestamos', 5);
    }

    /** TC-ROL-005 / RN-02: editar etiqueta, descripción y cupo sin cambiar el nombre técnico. */
    public function test_tc_rol_005_editar_un_rol_no_cambia_su_nombre_tecnico(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", [
                'etiqueta' => 'Docente',
                'descripcion' => 'Planta',
                'cupo_prestamos' => 7,
                'nombre' => 'otro_nombre',
                'name' => 'admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'profesor')
            ->assertJsonPath('data.etiqueta', 'Docente')
            ->assertJsonPath('data.cupo_prestamos', 7);

        $this->assertDatabaseHas('roles', ['id' => $profesor->id, 'name' => 'profesor', 'etiqueta' => 'Docente', 'descripcion' => 'Planta', 'cupo_prestamos' => 7]);
        $this->assertSame(1, Rol::where('name', 'admin')->count());
    }

    /** TC-ROL-006 / RN-02: los roles de sistema admiten cambiar etiqueta y cupo. */
    public function test_tc_rol_006_un_rol_de_sistema_admite_cambiar_etiqueta_y_cupo(): void
    {
        $encargado = Rol::where('name', 'encargado')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/roles/{$encargado->id}", ['etiqueta' => 'Recepción', 'cupo_prestamos' => 8])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'encargado')
            ->assertJsonPath('data.cupo_prestamos', 8);
    }

    /** TC-ROL-007 / RN-02: eliminar un rol de solicitante sin usuarios. */
    public function test_tc_rol_007_eliminar_un_rol_sin_usuarios_devuelve_204(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/roles/{$profesor->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('roles', ['id' => $profesor->id]);
    }

    // ---------------------------------------------------------------------
    // Permisos (seguridad)
    // ---------------------------------------------------------------------

    /** TC-ROL-008 / RN-02: el encargado no gestiona roles (403 en todo el CRUD). */
    public function test_tc_rol_008_el_encargado_recibe_403_en_todo_el_crud_de_roles(): void
    {
        $this->assertCrudProhibido($this->usuarioConRol('encargado'));
    }

    /** TC-ROL-009 / RN-02: un estudiante no gestiona roles (403 en todo el CRUD). */
    public function test_tc_rol_009_el_estudiante_recibe_403_en_todo_el_crud_de_roles(): void
    {
        $this->assertCrudProhibido($this->usuarioConRol('estudiante'));
    }

    /** TC-ROL-010 / RN-02: un rol personalizado (profesor) tampoco gestiona roles. */
    public function test_tc_rol_010_un_rol_personalizado_recibe_403_en_el_crud_de_roles(): void
    {
        $this->rol('profesor', 5);

        $this->assertCrudProhibido($this->usuarioConRol('profesor'));
    }

    private function assertCrudProhibido(User $user): void
    {
        $estudiante = Rol::where('name', 'estudiante')->firstOrFail();

        $this->actingAs($user, 'sanctum');
        $this->getJson('/api/roles')->assertForbidden();
        $this->getJson("/api/roles/{$estudiante->id}")->assertForbidden();
        $this->postJson('/api/roles', ['etiqueta' => 'Intruso', 'cupo_prestamos' => 99])->assertForbidden();
        $this->patchJson("/api/roles/{$estudiante->id}", ['cupo_prestamos' => 99])->assertForbidden();
        $this->deleteJson("/api/roles/{$estudiante->id}")->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'intruso']);
        $this->assertDatabaseHas('roles', ['id' => $estudiante->id, 'cupo_prestamos' => 3]);
    }

    /** TC-ROL-011 / RN-02: sin token, 401 en todo el CRUD. */
    public function test_tc_rol_011_sin_token_el_crud_de_roles_devuelve_401(): void
    {
        $estudiante = Rol::where('name', 'estudiante')->firstOrFail();

        $this->getJson('/api/roles')->assertUnauthorized();
        $this->getJson("/api/roles/{$estudiante->id}")->assertUnauthorized();
        $this->postJson('/api/roles', ['etiqueta' => 'Intruso', 'cupo_prestamos' => 1])->assertUnauthorized();
        $this->patchJson("/api/roles/{$estudiante->id}", ['cupo_prestamos' => 1])->assertUnauthorized();
        $this->deleteJson("/api/roles/{$estudiante->id}")->assertUnauthorized();

        $this->assertDatabaseHas('roles', ['id' => $estudiante->id, 'cupo_prestamos' => 3]);
    }

    /** TC-ROL-012 / RN-02: el encargado y el estudiante no cambian cupos por /configuracion. */
    public function test_tc_rol_012_solo_el_admin_cambia_cupos_por_configuracion(): void
    {
        foreach (['encargado', 'estudiante'] as $rol) {
            $this->actingAs($this->usuarioConRol($rol), 'sanctum')
                ->putJson('/api/configuracion', ['parametros' => ['cupos.roles' => ['estudiante' => 50]]])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('roles', ['name' => 'estudiante', 'cupo_prestamos' => 3]);
    }

    // ---------------------------------------------------------------------
    // Validaciones del alta (inválidos y límites)
    // ---------------------------------------------------------------------

    /** TC-ROL-013 / RN-02: etiqueta duplicada con distinto caso → 422. */
    public function test_tc_rol_013_una_etiqueta_duplicada_con_distinto_caso_es_rechazada(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson('/api/roles', ['etiqueta' => 'Profesor', 'cupo_prestamos' => 5])->assertCreated();

        foreach (['PROFESOR', 'profesor', ' pRoFeSoR '] as $variante) {
            $this->postJson('/api/roles', ['etiqueta' => $variante, 'cupo_prestamos' => 1])
                ->assertStatus(422)
                ->assertJsonValidationErrors('etiqueta');
        }

        $this->assertSame(1, Rol::where('name', 'profesor')->count());
    }

    /** TC-ROL-014 / RN-02: etiquetas distintas que producen el mismo nombre técnico → 422. */
    public function test_tc_rol_014_una_etiqueta_que_produce_un_nombre_existente_es_rechazada(): void
    {
        $this->actingAs($this->admin(), 'sanctum');
        $this->postJson('/api/roles', ['etiqueta' => 'Coordinación', 'cupo_prestamos' => 5])->assertCreated();

        $this->postJson('/api/roles', ['etiqueta' => 'Coordinacion!', 'cupo_prestamos' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('etiqueta');

        $this->assertSame(1, Rol::where('name', 'coordinacion')->count());
    }

    /** TC-ROL-015 / RN-02: nombres reservados (sistema, usuario, estudiante existente) → 422. */
    public function test_tc_rol_015_los_nombres_reservados_o_existentes_son_rechazados(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach (['Admin', 'ENCARGADO', 'Usuario', 'Estudiante', 'Administrador', 'Personal de préstamo'] as $etiqueta) {
            $this->postJson('/api/roles', ['etiqueta' => $etiqueta, 'cupo_prestamos' => 1])
                ->assertStatus(422)
                ->assertJsonValidationErrors('etiqueta');
        }

        $this->assertSame(3, Rol::count());
        $this->assertDatabaseMissing('roles', ['name' => 'usuario']);
    }

    /** TC-ROL-016 / RN-02: una etiqueta con solo símbolos no genera nombre técnico → 422. */
    public function test_tc_rol_016_una_etiqueta_con_solo_simbolos_es_rechazada(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach (['!!!', '***', '---', '¿?¡'] as $etiqueta) {
            $this->postJson('/api/roles', ['etiqueta' => $etiqueta, 'cupo_prestamos' => 1])
                ->assertStatus(422)
                ->assertJsonValidationErrors('etiqueta');
        }

        $this->assertSame(3, Rol::count());
        $this->assertDatabaseMissing('roles', ['name' => '']);
    }

    /** TC-ROL-017 / RN-02: límites del cupo en el alta: 0 y 1000 válidos; -1 y 1001 inválidos. */
    public function test_tc_rol_017_el_cupo_del_alta_acepta_0_y_1000_y_rechaza_menos_1_y_1001(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/roles', ['etiqueta' => 'Cupo cero', 'cupo_prestamos' => 0])
            ->assertCreated()->assertJsonPath('data.cupo_prestamos', 0);
        $this->postJson('/api/roles', ['etiqueta' => 'Cupo mil', 'cupo_prestamos' => 1000])
            ->assertCreated()->assertJsonPath('data.cupo_prestamos', 1000);

        $this->postJson('/api/roles', ['etiqueta' => 'Cupo negativo', 'cupo_prestamos' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('cupo_prestamos');
        $this->postJson('/api/roles', ['etiqueta' => 'Cupo excesivo', 'cupo_prestamos' => 1001])
            ->assertStatus(422)->assertJsonValidationErrors('cupo_prestamos');

        $this->assertDatabaseMissing('roles', ['name' => 'cupo_negativo']);
        $this->assertDatabaseMissing('roles', ['name' => 'cupo_excesivo']);
    }

    /** TC-ROL-018 / RN-02: campos obligatorios y tipos del alta. */
    public function test_tc_rol_018_el_alta_exige_etiqueta_y_cupo_entero(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/roles', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['etiqueta', 'cupo_prestamos']);

        $this->postJson('/api/roles', ['etiqueta' => 'Profesor', 'cupo_prestamos' => 2.5])
            ->assertStatus(422)->assertJsonValidationErrors('cupo_prestamos');

        $this->postJson('/api/roles', ['etiqueta' => 'Profesor', 'cupo_prestamos' => 'cinco'])
            ->assertStatus(422)->assertJsonValidationErrors('cupo_prestamos');

        $this->assertSame(3, Rol::count());
    }

    /** TC-ROL-019 / RN-02: límites de longitud de la etiqueta (3 y 60 válidos; 2 y 61 inválidos). */
    public function test_tc_rol_019_la_etiqueta_respeta_los_limites_de_longitud(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/roles', ['etiqueta' => 'Abc', 'cupo_prestamos' => 1])->assertCreated();
        $this->postJson('/api/roles', ['etiqueta' => str_repeat('b', 60), 'cupo_prestamos' => 1])->assertCreated();

        $this->postJson('/api/roles', ['etiqueta' => 'Xy', 'cupo_prestamos' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('etiqueta');
        $this->postJson('/api/roles', ['etiqueta' => str_repeat('c', 61), 'cupo_prestamos' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('etiqueta');

        $this->assertSame(5, Rol::count());
    }

    // ---------------------------------------------------------------------
    // Validaciones de la edición
    // ---------------------------------------------------------------------

    /** TC-ROL-020 / RN-02: editar la etiqueta a la de otro rol (distinto caso) → 422. */
    public function test_tc_rol_020_editar_con_la_etiqueta_de_otro_rol_es_rechazado(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", ['etiqueta' => 'ESTUDIANTE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('etiqueta');

        $this->assertDatabaseHas('roles', ['id' => $profesor->id, 'etiqueta' => 'Profesor']);
    }

    /** TC-ROL-021 / RN-02: un rol puede conservar su propia etiqueta al editar (no choca consigo mismo). */
    public function test_tc_rol_021_editar_conservando_la_propia_etiqueta_es_valido(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", ['etiqueta' => 'PROFESOR', 'cupo_prestamos' => 6])
            ->assertOk()
            ->assertJsonPath('data.etiqueta', 'PROFESOR');
    }

    /** TC-ROL-022 / RN-02: límites del cupo en la edición (1000 válido; 1001 y -1 inválidos). */
    public function test_tc_rol_022_el_cupo_de_la_edicion_respeta_los_limites(): void
    {
        $profesor = $this->rol('profesor', 5);
        $this->actingAs($this->admin(), 'sanctum');

        $this->patchJson("/api/roles/{$profesor->id}", ['cupo_prestamos' => 1001])
            ->assertStatus(422)->assertJsonValidationErrors('cupo_prestamos');
        $this->patchJson("/api/roles/{$profesor->id}", ['cupo_prestamos' => -1])
            ->assertStatus(422)->assertJsonValidationErrors('cupo_prestamos');
        $this->assertDatabaseHas('roles', ['id' => $profesor->id, 'cupo_prestamos' => 5]);

        $this->patchJson("/api/roles/{$profesor->id}", ['cupo_prestamos' => 1000])
            ->assertOk()->assertJsonPath('data.cupo_prestamos', 1000);
    }

    /** TC-ROL-023 / RN-02: editar un rol inexistente → 404. */
    public function test_tc_rol_023_un_rol_inexistente_devuelve_404(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        $this->getJson('/api/roles/99999')->assertNotFound();
        $this->patchJson('/api/roles/99999', ['cupo_prestamos' => 1])->assertNotFound();
        $this->deleteJson('/api/roles/99999')->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Rol predeterminado
    // ---------------------------------------------------------------------

    /** TC-ROL-024 / RN-02: marcar otro rol como predeterminado desmarca el anterior (solo uno). */
    public function test_tc_rol_024_marcar_un_rol_predeterminado_desmarca_el_anterior(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", ['es_predeterminado' => true])
            ->assertOk()
            ->assertJsonPath('data.es_predeterminado', true);

        $this->assertSame(['profesor'], Rol::where('es_predeterminado', true)->pluck('name')->all());
        $this->assertSame('profesor', Rol::predeterminado()?->name);
    }

    /** TC-ROL-025 / RN-02: crear un rol ya marcado como predeterminado desmarca el anterior. */
    public function test_tc_rol_025_crear_un_rol_predeterminado_desmarca_el_anterior(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/roles', ['etiqueta' => 'Visitante', 'cupo_prestamos' => 1, 'es_predeterminado' => true])
            ->assertCreated()
            ->assertJsonPath('data.es_predeterminado', true);

        $this->assertSame(['visitante'], Rol::where('es_predeterminado', true)->pluck('name')->all());
    }

    /** TC-ROL-026 / RN-02: desmarcar el predeterminado actual → 422 (siempre debe haber uno). */
    public function test_tc_rol_026_desmarcar_el_predeterminado_actual_es_rechazado(): void
    {
        $estudiante = Rol::where('name', 'estudiante')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$estudiante->id}", ['es_predeterminado' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('es_predeterminado');

        $this->assertTrue($estudiante->fresh()->es_predeterminado);
    }

    /** TC-ROL-027 / RN-02: un rol de sistema no puede ser predeterminado → 422. */
    public function test_tc_rol_027_un_rol_de_sistema_no_puede_ser_predeterminado(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach (['admin', 'encargado'] as $nombre) {
            $rol = Rol::where('name', $nombre)->firstOrFail();

            $this->patchJson("/api/roles/{$rol->id}", ['es_predeterminado' => true])
                ->assertStatus(422)
                ->assertJsonValidationErrors('es_predeterminado');

            $this->assertFalse($rol->fresh()->es_predeterminado);
        }

        $this->assertSame('estudiante', Rol::predeterminado()?->name);
    }

    // ---------------------------------------------------------------------
    // Eliminación
    // ---------------------------------------------------------------------

    /** TC-ROL-028 / RN-02: los roles de sistema no se eliminan. */
    public function test_tc_rol_028_los_roles_de_sistema_no_se_pueden_eliminar(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach (['admin', 'encargado'] as $nombre) {
            $rol = Rol::where('name', $nombre)->firstOrFail();

            $this->deleteJson("/api/roles/{$rol->id}")
                ->assertStatus(422)
                ->assertJsonPath('message', 'Los roles de sistema no se pueden eliminar.');

            $this->assertDatabaseHas('roles', ['id' => $rol->id]);
        }
    }

    /** TC-ROL-029 / RN-02: el rol predeterminado no se elimina (aunque no tenga usuarios). */
    public function test_tc_rol_029_el_rol_predeterminado_no_se_puede_eliminar(): void
    {
        $estudiante = Rol::where('name', 'estudiante')->firstOrFail();

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/roles/{$estudiante->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('roles', ['id' => $estudiante->id]);
    }

    /** TC-ROL-030 / RN-02: un rol con usuarios asignados no se elimina y sus usuarios lo conservan. */
    public function test_tc_rol_030_un_rol_con_usuarios_no_se_puede_eliminar(): void
    {
        $profesor = $this->rol('profesor', 5);
        $docente = $this->usuarioConRol('profesor');

        $this->actingAs($this->admin(), 'sanctum')
            ->deleteJson("/api/roles/{$profesor->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'El rol tiene 1 usuario(s) asignado(s). Cámbiales el rol antes de eliminarlo.');

        $this->assertDatabaseHas('roles', ['id' => $profesor->id]);
        $this->assertTrue($docente->fresh()->hasRole('profesor'));
    }

    // ---------------------------------------------------------------------
    // RN-02 aplicado a la solicitud de préstamos
    // ---------------------------------------------------------------------

    /** TC-ROL-031 / RN-02 (límite): profesor con cupo 5 y 4 vivos puede pedir el 5.º. */
    public function test_tc_rol_031_un_profesor_con_cupo_5_puede_tener_exactamente_5_vivos(): void
    {
        $this->rol('profesor', 5);
        $docente = $this->usuarioConRol('profesor');
        $this->prestamosVivos($docente, 4);

        $this->solicitar($docente)->assertCreated();

        $this->assertSame(5, Prestamo::where('usuario_id', $docente->id)->count());
    }

    /** TC-ROL-032 / RN-02 (límite + 1): el 6.º préstamo vivo del profesor se rechaza con 422. */
    public function test_tc_rol_032_el_sexto_prestamo_vivo_de_un_profesor_con_cupo_5_es_rechazado(): void
    {
        $this->rol('profesor', 5);
        $docente = $this->usuarioConRol('profesor');
        $this->prestamosVivos($docente, 5);

        $this->solicitar($docente)
            ->assertStatus(422)
            ->assertJsonValidationErrors('usuario_id')
            ->assertJsonPath('errors.usuario_id.0', 'Has alcanzado el límite máximo de 5 préstamos activos.');

        $this->assertSame(5, Prestamo::where('usuario_id', $docente->id)->count());
    }

    /** TC-ROL-033 / RN-02: el cupo personalizado es independiente del de estudiante (3). */
    public function test_tc_rol_033_el_cupo_del_profesor_no_usa_el_del_estudiante(): void
    {
        $this->rol('profesor', 5);
        $docente = $this->usuarioConRol('profesor');
        $alumno = $this->usuarioConRol('estudiante');
        $this->prestamosVivos($docente, 3);
        $this->prestamosVivos($alumno, 3);

        $this->solicitar($docente)->assertCreated();
        $this->solicitar($alumno)->assertStatus(422)->assertJsonValidationErrors('usuario_id');
    }

    /** TC-ROL-034 / RN-02 (límite 0): un rol con cupo 0 no puede pedir ningún préstamo. */
    public function test_tc_rol_034_un_rol_con_cupo_0_bloquea_la_primera_solicitud(): void
    {
        $this->rol('visitante', 0);
        $visitante = $this->usuarioConRol('visitante');

        $this->solicitar($visitante)
            ->assertStatus(422)
            ->assertJsonPath('errors.usuario_id.0', 'Has alcanzado el límite máximo de 0 préstamos activos.');

        $this->assertSame(0, Prestamo::where('usuario_id', $visitante->id)->count());
    }

    /** TC-ROL-035 / RN-02: los préstamos devueltos no consumen cupo del rol personalizado. */
    public function test_tc_rol_035_los_prestamos_devueltos_no_cuentan_para_el_cupo(): void
    {
        $this->rol('profesor', 1);
        $docente = $this->usuarioConRol('profesor');
        Prestamo::factory()->devuelto()->count(3)->create(['usuario_id' => $docente->id]);

        $this->solicitar($docente)->assertCreated();
    }

    /** TC-ROL-036 / RN-02: cambiar el cupo del rol por /api/roles se aplica de inmediato. */
    public function test_tc_rol_036_cambiar_el_cupo_del_rol_por_la_api_de_roles_aplica_de_inmediato(): void
    {
        $profesor = $this->rol('profesor', 1);
        $docente = $this->usuarioConRol('profesor');
        $this->prestamosVivos($docente, 1);

        $this->solicitar($docente)->assertStatus(422);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", ['cupo_prestamos' => 2])
            ->assertOk();

        $this->solicitar($docente)->assertCreated();
        $this->solicitar($docente)->assertStatus(422)
            ->assertJsonPath('errors.usuario_id.0', 'Has alcanzado el límite máximo de 2 préstamos activos.');
    }

    /** TC-ROL-037 / RN-02: bajar el cupo por debajo de los vivos bloquea nuevas solicitudes (no anula las existentes). */
    public function test_tc_rol_037_bajar_el_cupo_por_debajo_de_los_vivos_bloquea_sin_anular_los_existentes(): void
    {
        $profesor = $this->rol('profesor', 5);
        $docente = $this->usuarioConRol('profesor');
        $this->prestamosVivos($docente, 3);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", ['cupo_prestamos' => 1])
            ->assertOk();

        $this->solicitar($docente)->assertStatus(422);
        $this->assertSame(3, Prestamo::where('usuario_id', $docente->id)
            ->where('estado', EstadoPrestamo::Solicitado)->count());
    }

    /** TC-ROL-038 / RN-02: cambiar el cupo por PUT /configuracion (cupos.roles) también aplica de inmediato. */
    public function test_tc_rol_038_cambiar_el_cupo_por_configuracion_aplica_de_inmediato_y_vive_en_roles(): void
    {
        $this->rol('profesor', 1);
        $docente = $this->usuarioConRol('profesor');
        $this->prestamosVivos($docente, 1);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['cupos.roles' => ['profesor' => 2]]])
            ->assertOk()
            ->assertJsonPath('data.cupos.roles.profesor', 2);

        $this->assertDatabaseHas('roles', ['name' => 'profesor', 'cupo_prestamos' => 2]);
        $this->assertDatabaseMissing('configuraciones', ['clave' => 'cupos.roles']);

        $this->solicitar($docente)->assertCreated();
    }

    /** TC-ROL-039 / RN-02: cambiar el rol de un usuario por /api/usuarios cambia su cupo. */
    public function test_tc_rol_039_cambiar_el_rol_de_un_usuario_cambia_su_cupo(): void
    {
        $this->rol('profesor', 5);
        $persona = $this->usuarioConRol('estudiante');
        $this->prestamosVivos($persona, 3);

        $this->solicitar($persona)->assertStatus(422);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson("/api/usuarios/{$persona->id}", ['role' => 'profesor'])
            ->assertOk()
            ->assertJsonPath('data.roles', ['profesor'])
            ->assertJsonPath('data.rol_principal', 'profesor')
            ->assertJsonPath('data.rol_etiqueta', 'Profesor');

        $this->assertFalse($persona->fresh()->hasRole('estudiante'));
        $this->solicitar($persona)->assertCreated();
    }

    /** TC-ROL-040 / RN-02: un usuario sin roles usa el cupo del rol predeterminado vigente. */
    public function test_tc_rol_040_un_usuario_sin_rol_usa_el_cupo_del_predeterminado_vigente(): void
    {
        $this->rol('profesor', 1);
        Rol::query()->update(['es_predeterminado' => false]);
        Rol::where('name', 'profesor')->update(['es_predeterminado' => true]);

        $sinRol = $this->usuarioConRol(null);
        $this->assertSame('profesor', $sinRol->rolPrincipal());
        $this->prestamosVivos($sinRol, 1);

        $this->solicitar($sinRol)
            ->assertStatus(422)
            ->assertJsonPath('errors.usuario_id.0', 'Has alcanzado el límite máximo de 1 préstamos activos.');
    }

    // ---------------------------------------------------------------------
    // Alta de usuarios y auto-registro
    // ---------------------------------------------------------------------

    /** TC-ROL-041 / RN-02: el admin crea usuarios con un rol personalizado; un rol inexistente → 422. */
    public function test_tc_rol_041_el_alta_de_usuarios_acepta_roles_existentes_y_rechaza_inexistentes(): void
    {
        $this->rol('profesor', 5);
        $this->actingAs($this->admin(), 'sanctum');

        $this->postJson('/api/usuarios', [
            'name' => 'Docente Uno',
            'email' => 'docente.uno@ecci.edu.co',
            'password' => 'secreta123',
            'role' => 'profesor',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rol_principal', 'profesor');

        $this->postJson('/api/usuarios', [
            'name' => 'Fantasma',
            'email' => 'fantasma@ecci.edu.co',
            'password' => 'secreta123',
            'role' => 'fantasma',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'fantasma@ecci.edu.co']);

        // El rol renombrado ya no existe: «usuario» deja de ser válido.
        $this->postJson('/api/usuarios', [
            'name' => 'Viejo',
            'email' => 'viejo@ecci.edu.co',
            'password' => 'secreta123',
            'role' => 'usuario',
        ])->assertStatus(422)->assertJsonValidationErrors('role');
    }

    /** TC-ROL-042 / RN-02: el auto-registro asigna el rol predeterminado vigente (no estudiante si se cambió). */
    public function test_tc_rol_042_el_auto_registro_asigna_el_predeterminado_vigente(): void
    {
        $profesor = $this->rol('profesor', 5);

        $this->actingAs($this->admin(), 'sanctum')
            ->patchJson("/api/roles/{$profesor->id}", ['es_predeterminado' => true])
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/register', [
            'name' => 'Docente Registrado',
            'email' => 'docente.registrado@ecci.edu.co',
            'password' => 'secreta123',
            'password_confirmation' => 'secreta123',
            'role' => 'admin',
        ])->assertCreated();

        $user = User::where('email', 'docente.registrado@ecci.edu.co')->firstOrFail();

        $this->assertSame(['profesor'], $user->getRoleNames()->all());
        $this->assertSame('profesor', $user->rolPrincipal());
    }

    // ---------------------------------------------------------------------
    // /api/configuracion — clave virtual cupos.roles
    // ---------------------------------------------------------------------

    /** TC-ROL-043 / RN-02: PUT /configuracion con un rol inexistente → 422 y no cambia ningún cupo. */
    public function test_tc_rol_043_put_configuracion_con_rol_inexistente_es_rechazado_sin_cambios(): void
    {
        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => ['cupos.roles' => ['estudiante' => 7, 'fantasma' => 2]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parametros.cupos.roles');

        $this->assertDatabaseHas('roles', ['name' => 'estudiante', 'cupo_prestamos' => 3]);
        $this->assertDatabaseMissing('roles', ['name' => 'fantasma']);
    }

    /** TC-ROL-044 / RN-02: límites del cupo en /configuracion (1000 válido; 1001, -1 y no entero inválidos). */
    public function test_tc_rol_044_put_configuracion_respeta_los_limites_del_cupo(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach ([1001, -1, 2.5, 'tres', null] as $invalido) {
            $this->putJson('/api/configuracion', ['parametros' => ['cupos.roles' => ['estudiante' => $invalido]]])
                ->assertStatus(422)
                ->assertJsonValidationErrors('parametros.cupos.roles');
        }

        $this->assertDatabaseHas('roles', ['name' => 'estudiante', 'cupo_prestamos' => 3]);

        $this->putJson('/api/configuracion', ['parametros' => ['cupos.roles' => ['estudiante' => 1000]]])
            ->assertOk()
            ->assertJsonPath('data.cupos.roles.estudiante', 1000);
    }

    /** TC-ROL-045 / RN-02: GET /configuracion expone el catálogo de roles y el cupo del rol personalizado. */
    public function test_tc_rol_045_get_configuracion_incluye_el_catalogo_de_roles(): void
    {
        $this->rol('profesor', 5);
        $this->usuarioConRol('profesor');

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/configuracion')
            ->assertOk()
            ->assertJsonPath('data.cupos.roles.profesor', 5)
            ->assertJsonPath('data.cupos.roles.estudiante', 3);

        $profesor = collect($response->json('data.roles'))->firstWhere('nombre', 'profesor');

        $this->assertNotNull($profesor);
        $this->assertSame(5, $profesor['cupo_prestamos']);
        $this->assertSame(1, $profesor['usuarios_count']);
        $this->assertCount(4, $response->json('data.roles'));
    }

    // ---------------------------------------------------------------------
    // RN-06 — duración extra para un rol personalizado
    // ---------------------------------------------------------------------

    /** TC-ROL-046 / RN-06: duracion.roles_extra aplica a un rol personalizado (exacto válido, +1 inválido). */
    public function test_tc_rol_046_los_dias_extra_de_un_rol_personalizado_amplian_la_duracion(): void
    {
        $this->rol('profesor', 5);
        $categoria = Categoria::create(['nombre' => 'Portátiles '.uniqid()]);

        $this->actingAs($this->admin(), 'sanctum')
            ->putJson('/api/configuracion', ['parametros' => [
                'duracion.categorias' => [(string) $categoria->id => 4],
                'duracion.roles_extra' => ['estudiante' => 0, 'encargado' => 2, 'admin' => 3, 'profesor' => 2],
            ]])
            ->assertOk()
            ->assertJsonPath('data.duracion.roles_extra.profesor', 2);

        $docente = $this->usuarioConRol('profesor');
        $alumno = $this->usuarioConRol('estudiante');
        $equipo = fn () => Equipo::factory()->create(['categoria_id' => $categoria->id, 'estado' => Equipo::ESTADO_DISPONIBLE]);

        // Profesor: 4 + 2 = 6 días.
        $this->solicitar($docente, $this->solicitud($equipo(), 6))->assertCreated();
        $this->solicitar($docente, $this->solicitud($equipo(), 7))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha_devolucion_estimada');

        // Estudiante: 4 + 0 = 4 días; 6 días se rechaza.
        $this->solicitar($alumno, $this->solicitud($equipo(), 6))
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha_devolucion_estimada');
    }

    /** TC-ROL-047 / RN-06: el detalle del equipo expone la duración máxima del rol personalizado. */
    public function test_tc_rol_047_el_detalle_del_equipo_expone_la_duracion_del_rol_personalizado(): void
    {
        $this->rol('profesor', 5);
        $categoria = Categoria::create(['nombre' => 'Cámaras '.uniqid()]);
        $this->parametro('duracion.categorias', [(string) $categoria->id => 4]);
        $this->parametro('duracion.roles_extra', ['profesor' => 3]);
        $equipo = Equipo::factory()->create(['categoria_id' => $categoria->id, 'estado' => Equipo::ESTADO_DISPONIBLE]);

        $response = $this->actingAs($this->usuarioConRol('profesor'), 'sanctum')
            ->getJson("/api/equipos/{$equipo->id}")
            ->assertOk();

        $this->assertSame(7, $response->json('data.duracion_maxima_dias'));
    }
}
