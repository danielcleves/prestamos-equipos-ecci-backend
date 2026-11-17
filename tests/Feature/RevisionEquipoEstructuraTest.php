<?php

namespace Tests\Feature;

use App\Enums\CondicionEquipo;
use App\Models\Equipo;
use App\Models\Prestamo;
use App\Models\RevisionEquipo;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * HU-12 / KAN-125: estructura de la entidad de revision.
 */
class RevisionEquipoEstructuraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_la_tabla_revisiones_equipo_existe_con_sus_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('revisiones_equipo'));

        $this->assertTrue(Schema::hasColumns('revisiones_equipo', [
            'id',
            'prestamo_id',
            'equipo_id',
            'condicion',
            'tiene_danos',
            'observaciones',
            'revisado_por',
            'fecha_revision',
            'incidencia_id',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_el_modelo_apunta_a_la_tabla_revisiones_equipo(): void
    {
        $this->assertSame('revisiones_equipo', (new RevisionEquipo)->getTable());
    }

    public function test_la_factory_crea_una_revision_con_casts_correctos(): void
    {
        $revision = RevisionEquipo::factory()->create();

        $fresca = RevisionEquipo::findOrFail($revision->id);

        $this->assertInstanceOf(CondicionEquipo::class, $fresca->condicion);
        $this->assertIsBool($fresca->tiene_danos);
        $this->assertNotNull($fresca->fecha_revision);
        $this->assertSame(
            now()->toDateString(),
            $fresca->fecha_revision->toDateString()
        );
    }

    public function test_estados_de_la_factory_derivan_tiene_danos(): void
    {
        $bueno = RevisionEquipo::factory()->bueno()->create();
        $this->assertFalse($bueno->fresh()->tiene_danos);
        $this->assertSame(CondicionEquipo::Bueno, $bueno->fresh()->condicion);

        $conDanos = RevisionEquipo::factory()->conDanos()->create();
        $this->assertTrue($conDanos->fresh()->tiene_danos);
        $this->assertSame(CondicionEquipo::ConDanos, $conDanos->fresh()->condicion);
        $this->assertNotNull($conDanos->fresh()->observaciones);

        $mantenimiento = RevisionEquipo::factory()->requiereMantenimiento()->create();
        $this->assertTrue($mantenimiento->fresh()->tiene_danos);
        $this->assertSame(CondicionEquipo::RequiereMantenimiento, $mantenimiento->fresh()->condicion);
        $this->assertNotNull($mantenimiento->fresh()->observaciones);
    }

    public function test_condicion_tiene_danos_es_la_fuente_de_verdad_de_la_derivacion(): void
    {
        $this->assertFalse(RevisionEquipo::condicionTieneDanos(CondicionEquipo::Bueno));
        $this->assertTrue(RevisionEquipo::condicionTieneDanos(CondicionEquipo::ConDanos));
        $this->assertTrue(RevisionEquipo::condicionTieneDanos(CondicionEquipo::RequiereMantenimiento));
    }

    public function test_un_prestamo_solo_puede_tener_una_revision(): void
    {
        $prestamo = Prestamo::factory()->entregado()->create();

        RevisionEquipo::factory()->create([
            'prestamo_id' => $prestamo->id,
            'equipo_id' => $prestamo->equipo_id,
        ]);

        $this->expectException(QueryException::class);

        RevisionEquipo::factory()->create([
            'prestamo_id' => $prestamo->id,
            'equipo_id' => $prestamo->equipo_id,
        ]);
    }

    public function test_prestamo_expone_la_relacion_revision_como_has_one(): void
    {
        $prestamo = Prestamo::factory()->entregado()->create();

        $this->assertNull($prestamo->revision);

        $revision = RevisionEquipo::factory()->conDanos()->create([
            'prestamo_id' => $prestamo->id,
            'equipo_id' => $prestamo->equipo_id,
        ]);

        $this->assertNotNull($prestamo->fresh()->revision);
        $this->assertSame($revision->id, $prestamo->fresh()->revision->id);
        $this->assertSame($prestamo->id, $revision->prestamo->id);
    }

    public function test_equipo_expone_sus_revisiones_mas_recientes_primero(): void
    {
        $equipo = Equipo::factory()->create();

        $primera = RevisionEquipo::factory()->create([
            'equipo_id' => $equipo->id,
            'fecha_revision' => now()->subDays(3),
        ]);
        $segunda = RevisionEquipo::factory()->create([
            'equipo_id' => $equipo->id,
            'fecha_revision' => now()->subDay(),
        ]);

        $revisiones = $equipo->fresh()->revisiones;

        $this->assertCount(2, $revisiones);
        $this->assertSame($segunda->id, $revisiones->first()->id);
        $this->assertSame($primera->id, $revisiones->last()->id);
        $this->assertSame($equipo->id, $primera->equipo->id);
    }

    public function test_revisado_por_queda_nulo_si_se_elimina_el_usuario(): void
    {
        $revisor = User::factory()->create();
        $revisor->assignRole('encargado');

        $revision = RevisionEquipo::factory()->create(['revisado_por' => $revisor->id]);

        $this->assertSame($revisor->id, $revision->revisadoPor->id);

        $revisor->delete();

        $this->assertNull($revision->fresh()->revisado_por);
        $this->assertNull($revision->fresh()->revisadoPor);
    }

    public function test_incidencia_id_es_nulo_por_defecto_y_acepta_un_id_sin_foreign_key(): void
    {
        $revision = RevisionEquipo::factory()->create();
        $this->assertNull($revision->fresh()->incidencia_id);

        // La tabla de incidencias (HU-31) todavia no existe: la columna debe
        // aceptar un id arbitrario sin restriccion de integridad.
        $vinculada = RevisionEquipo::factory()->conIncidencia(987654)->create();
        $this->assertSame(987654, $vinculada->fresh()->incidencia_id);
    }

    public function test_scope_con_danos_filtra_solo_revisiones_con_novedad(): void
    {
        RevisionEquipo::factory()->bueno()->create();
        RevisionEquipo::factory()->conDanos()->create();
        RevisionEquipo::factory()->requiereMantenimiento()->create();

        $this->assertSame(3, RevisionEquipo::count());
        $this->assertSame(2, RevisionEquipo::conDanos()->count());
    }
}
