<?php

namespace Tests\Feature\Comandos;

use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use App\Models\Administrador;
use Database\Seeders\TarifasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** evento:respaldo, evento:restaurar y evento:limpiar. */
class RespaldoLimpiezaTest extends TestCase
{
    use ConCarpetaTemporal, RefreshDatabase;

    private const CLAVE = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    protected function setUp(): void
    {
        parent::setUp();
        config(['evento.respaldo.dir' => $this->carpetaTemporal('respaldos'), 'evento.respaldo.clave' => null]);
        $this->seed(TarifasSeeder::class);
    }

    /** Una inscripción con su soporte y comprobante, un asociado, un cupo ajustado y un registro de auditoría. */
    private function crearDatos(): void
    {
        $ahora = now();
        $id = DB::table('inscripciones')->insertGetId([
            'referencia' => 'EVT26-000001', 'documento_titular' => '1001', 'nombre_titular' => 'Ana María Torres', 'agencia' => 'BOGOTA',
            'agencia_asociado' => 'BOGOTA', 'total' => 43500, 'estado' => 'EN_REVISION', 'creada_en' => $ahora, 'actualizada_en' => $ahora,
        ]);
        DB::table('inscripcion_personas')->insert([
            'inscripcion_id' => $id, 'documento' => '1001', 'nombre' => 'Ana María Torres', 'tipo' => 'TITULAR', 'valor' => 43500,
        ]);
        DB::table('soportes')->insert([
            'inscripcion_id' => $id, 'cus' => '123456', 'fecha_pago' => '2026-10-05', 'valor_pagado' => 43500, 'campos' => '{}',
            'archivo' => app(AlmacenComprobantes::class)->guardar('%PDF-1.4 prueba', TipoComprobante::Pdf),
            'tipo_archivo' => 'application/pdf', 'cargado_en' => $ahora,
        ]);
        DB::table('asociados')->insert(['documento' => '1001', 'nombre' => 'Ana María Torres', 'agencia' => 'BOGOTA']);
        DB::table('cupos_ajustados')->insert(['agencia' => 'BOGOTA', 'cupos' => 320]);
        DB::table('auditoria')->insert(['usuario' => 'admin', 'accion' => 'CREAR', 'entidad' => 'asociado', 'clave' => '1001', 'fecha' => $ahora]);
        Administrador::query()->create(['usuario' => 'admin', 'nombre' => 'Administrador', 'clave' => 'x']);
    }

    public function test_respaldo_cifrado_y_restauracion(): void
    {
        $this->requierePgDump();
        config(['evento.respaldo.clave' => self::CLAVE]);

        $this->artisan('evento:respaldo')->expectsOutputToContain('Respaldo listo en')->assertSuccessful();

        $carpetas = glob(config('evento.respaldo.dir').'/*', GLOB_ONLYDIR);
        $this->assertCount(1, $carpetas);
        $this->assertMatchesRegularExpression('/^\d{8}-\d{4}$/', basename($carpetas[0]));
        $archivos = array_map('basename', glob($carpetas[0].'/*'));
        sort($archivos);
        $this->assertSame(['datos.tar.gz.enc', 'evento.dump.enc'], $archivos); // sin copias en texto plano

        $destino = $this->carpetaTemporal('descifrado');
        $this->artisan('evento:restaurar', ['origen' => $carpetas[0], 'destino' => $destino])
            ->expectsOutputToContain('pg_restore --clean --if-exists --no-owner')
            ->assertSuccessful();
        $this->assertStringStartsWith('PGDMP', file_get_contents("{$destino}/evento.dump"));
        $this->assertStringStartsWith("\x1f\x8b", file_get_contents("{$destino}/datos.tar.gz"));
    }

    public function test_respaldo_sin_clave_avisa_que_no_esta_cifrado_y_borra_los_antiguos(): void
    {
        $this->requierePgDump();
        $base = config('evento.respaldo.dir');
        mkdir("{$base}/20200101-0000");
        mkdir("{$base}/otra-carpeta");

        $this->artisan('evento:respaldo')
            ->expectsOutput('Aviso: RESPALDO_CLAVE no está definida; el respaldo NO está cifrado.')
            ->expectsOutput('Respaldo antiguo eliminado: 20200101-0000')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist("{$base}/20200101-0000");
        $this->assertDirectoryExists("{$base}/otra-carpeta"); // solo se borran carpetas con el formato AAAAMMDD-HHMM
    }

    public function test_sin_pg_dump_el_respaldo_falla_con_un_mensaje_claro(): void
    {
        config(['evento.respaldo.pg_dump' => 'pg_dump_que_no_existe']);

        $this->artisan('evento:respaldo')
            ->expectsOutputToContain('Instale el cliente de PostgreSQL')
            ->assertFailed();
        $this->assertSame([], glob(config('evento.respaldo.dir').'/*'));
    }

    public function test_restaurar_exige_la_clave(): void
    {
        $origen = $this->carpetaTemporal('origen');
        file_put_contents("{$origen}/evento.dump.enc", 'x');

        $this->artisan('evento:restaurar', ['origen' => $origen, 'destino' => $this->carpetaTemporal('destino')])
            ->expectsOutput('Defina RESPALDO_CLAVE (la misma con la que se hizo el respaldo).')
            ->assertFailed();
    }

    public function test_limpiar_sin_confirmar_solo_muestra_los_conteos(): void
    {
        $this->crearDatos();

        $this->artisan('evento:limpiar', ['que' => 'inscripciones'])
            ->expectsOutput('Se borrarían:')
            ->expectsOutputToContain('Vista previa: no se borró nada.')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('inscripciones')->count());
        $this->assertSame(1, DB::table('soportes_archivos')->count());
    }

    public function test_limpiar_no_borra_nada_si_el_respaldo_falla(): void
    {
        $this->crearDatos();
        config(['evento.respaldo.pg_dump' => 'pg_dump_que_no_existe']);

        $this->artisan('evento:limpiar', ['que' => 'todo', '--confirmar' => true])
            ->expectsOutput('No se limpió nada porque el respaldo falló.')
            ->assertFailed();

        $this->assertSame(1, DB::table('inscripciones')->count());
        $this->assertSame(1, DB::table('asociados')->count());
    }

    public function test_limpiar_inscripciones_reinicia_los_consecutivos_y_conserva_lo_demas(): void
    {
        $this->requierePgDump();
        $this->crearDatos();

        $this->artisan('evento:limpiar', ['que' => 'inscripciones', '--confirmar' => true])
            ->expectsOutputToContain('Limpieza terminada.')
            ->assertSuccessful();

        foreach (['soportes', 'inscripcion_personas', 'inscripciones', 'soportes_archivos'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count(), $tabla);
        }
        $this->assertSame(1, DB::table('asociados')->count());
        $this->assertSame(1, DB::table('auditoria')->count());
        $this->assertSame(1, DB::table('cupos_ajustados')->count());
        $this->assertSame(1, Administrador::query()->count());
        $this->assertSame(19, DB::table('tarifas')->count());
        $this->assertCount(1, glob(config('evento.respaldo.dir').'/*', GLOB_ONLYDIR)); // respaldo previo

        // La próxima inscripción vuelve a ser la número 1 (referencia EVTaa-000001).
        $id = DB::table('inscripciones')->insertGetId([
            'referencia' => 'EVT26-000001', 'documento_titular' => '1001', 'nombre_titular' => 'Ana', 'agencia' => 'BOGOTA',
            'total' => 1, 'estado' => 'PREINSCRITO', 'creada_en' => now(), 'actualizada_en' => now(),
        ]);
        $this->assertSame(1, $id);
    }

    public function test_limpiar_todo_conserva_usuarios_y_tarifas(): void
    {
        $this->requierePgDump();
        $this->crearDatos();

        $this->artisan('evento:limpiar', ['que' => 'todo', '--confirmar' => true])->assertSuccessful();

        foreach (['inscripciones', 'asociados', 'coopetrolitos', 'cargas_bases', 'auditoria', 'cupos_ajustados'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->count(), $tabla);
        }
        $this->assertSame(1, Administrador::query()->count());
        $this->assertSame(19, DB::table('tarifas')->count());
    }

    public function test_limpiar_rechaza_un_grupo_desconocido(): void
    {
        $this->artisan('evento:limpiar', ['que' => 'administradores'])->assertFailed();
    }
}
