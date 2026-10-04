<?php

namespace Tests\Feature;

use App\Aplicacion\Gestion\ConsultarAuditoria;
use App\Aplicacion\Gestion\GestionAsociados;
use App\Aplicacion\Gestion\GestionCoopetrolitos;
use App\Aplicacion\Simulacion\IdentificarAsociado;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Seguridad\HuellaFecha;
use App\Models\Asociado;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Gestión manual de asociados y Coopetrolitos desde el panel, con auditoría (hoy = 05/10/2026). */
class GestionPadronTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    private const LAURA = [
        'documento' => '80123456', 'nombre' => 'Laura Gómez Díaz', 'agencia' => 'cali', 'estado' => 'ACTIVO',
        'fechaActualizacion' => '2026-08-01', 'fechaExpedicion' => '2004-06-15',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->configurarEvento(['inscripciones_desde' => '2026-10-01', 'inscripciones_hasta' => '2026-10-31']);
    }

    private function asociados(): GestionAsociados
    {
        return app(GestionAsociados::class);
    }

    private function coopetrolitos(): GestionCoopetrolitos
    {
        return app(GestionCoopetrolitos::class);
    }

    public function test_crear_asociado_guarda_solo_la_huella_de_la_expedicion(): void
    {
        $this->asociados()->crear(self::LAURA, 'admin');

        $fila = Asociado::query()->find('80123456');
        $this->assertSame('CALI', $fila->agencia);
        $this->assertSame(app(HuellaFecha::class)->calcular('2004-06-15'), $fila->expedicion_hmac);

        $lista = $this->asociados()->listar('Laura');
        $this->assertSame(1, $lista['total']);
        $this->assertSame(1, $lista['filas'][0]['expedicion_registrada']);
        $this->assertArrayNotHasKey('expedicion_hmac', $lista['filas'][0]);

        $this->assertSame('80123456', app(IdentificarAsociado::class)->ejecutar('80123456', '2004-06-15')->asociado->documento);
    }

    public function test_validaciones_al_crear_asociado(): void
    {
        $this->asociados()->crear(self::LAURA, 'admin');
        $nuevo = [...self::LAURA, 'documento' => '80123457'];

        $this->assertRechaza('/Ya existe/', fn () => $this->asociados()->crear(self::LAURA, 'admin'));
        $this->assertRechaza('/agencia/', fn () => $this->asociados()->crear([...$nuevo, 'agencia' => 'MARTE'], 'admin'));
        $this->assertRechaza('/nombres y apellidos/', fn () => $this->asociados()->crear([...$nuevo, 'nombre' => 'Laura'], 'admin'));
        $this->assertRechaza('/letras y espacios/', fn () => $this->asociados()->crear([...$nuevo, 'nombre' => 'Laura G0mez'], 'admin'));
        $this->assertRechaza('/no es válida/', fn () => $this->asociados()->crear([...$nuevo, 'fechaExpedicion' => '2004-02-31'], 'admin'));
        $this->assertRechaza('/fecha de expedición/', fn () => $this->asociados()->crear([...$nuevo, 'fechaExpedicion' => ''], 'admin'));
        $this->assertRechaza('/números/', fn () => $this->asociados()->crear([...$nuevo, 'documento' => 'AB12'], 'admin'));
    }

    public function test_editar_conserva_la_expedicion_si_no_se_envia(): void
    {
        $this->asociados()->crear(self::LAURA, 'admin');
        $huellaOriginal = Asociado::query()->find('80123456')->expedicion_hmac;

        $this->asociados()->editar('80123456', [...self::LAURA, 'nombre' => 'Laura Gómez Ruiz', 'estado' => 'INACTIVO', 'fechaExpedicion' => ''], 'admin');
        $fila = Asociado::query()->find('80123456');
        $this->assertSame('Laura Gómez Ruiz', $fila->nombre);
        $this->assertSame('INACTIVO', $fila->estado);
        $this->assertSame($huellaOriginal, $fila->expedicion_hmac);

        $this->asociados()->editar('80123456', [...self::LAURA, 'fechaExpedicion' => '2004-06-16'], 'admin');
        $this->assertSame(app(HuellaFecha::class)->calcular('2004-06-16'), Asociado::query()->find('80123456')->expedicion_hmac);
    }

    public function test_auditoria_sin_fechas_de_expedicion(): void
    {
        $this->asociados()->crear(self::LAURA, 'admin');
        $this->travel(1)->minutes();
        $this->asociados()->editar('80123456', [...self::LAURA, 'fechaExpedicion' => '2004-06-16'], 'admin');

        $auditoria = app(ConsultarAuditoria::class)->ejecutar();
        $this->assertSame(['EDITAR', 'CREAR'], array_column($auditoria, 'accion'));
        $this->assertSame('incluye fecha de expedición', $auditoria[0]['detalle']);

        $texto = json_encode(DB::table('auditoria')->get());
        $this->assertStringNotContainsString('2004-06-15', $texto);
        $this->assertStringNotContainsString('2004-06-16', $texto);
    }

    public function test_eliminar_asociado(): void
    {
        $this->preinscribir('1002');
        $this->assertRechaza('/inscripción activa/', fn () => $this->asociados()->eliminar('1002', 'admin'));
        $this->assertRechaza('/Coopetrolito/', fn () => $this->asociados()->eliminar('1001', 'admin'));

        $this->asociados()->eliminar('5001', 'admin');
        $this->assertNull(Asociado::query()->find('5001'));
    }

    public function test_paginacion_y_asociado_inexistente(): void
    {
        $lista = $this->asociados()->listar();
        $this->assertSame(10, $lista['total']);
        $this->assertSame(1, $lista['paginas']);

        $this->expectException(ErrorValidacion::class);
        $this->asociados()->editar('99999999', self::LAURA, 'admin');
    }

    public function test_fecha_de_nacimiento_opcional(): void
    {
        $this->asociados()->crear([...self::LAURA, 'fechaNacimiento' => '1985-05-20'], 'admin');
        $this->assertSame('1985-05-20', $this->asociados()->listar('Laura')['filas'][0]['fecha_nacimiento']);

        $this->asociados()->editar('80123456', [...self::LAURA, 'fechaNacimiento' => ''], 'admin');
        $this->assertNull(Asociado::query()->find('80123456')->fecha_nacimiento);

        $this->assertRechaza('/nacimiento no es válida/',
            fn () => $this->asociados()->editar('80123456', [...self::LAURA, 'fechaNacimiento' => '2999-01-01'], 'admin'));
    }

    public function test_gestion_de_coopetrolitos(): void
    {
        $nino = ['documento' => '1100050', 'nombre' => 'Tomás Torres', 'documentoAsociado' => '1001', 'fechaNacimiento' => '2015-06-10'];

        $this->assertRechaza('/No existe un asociado/', fn () => $this->coopetrolitos()->crear([...$nino, 'documentoAsociado' => '7777777'], 'admin'));
        $this->assertRechaza('/números/', fn () => $this->coopetrolitos()->crear([...$nino, 'documentoAsociado' => '30O1'], 'admin'));

        $this->coopetrolitos()->crear($nino, 'admin');
        $this->assertRechaza('/Ya existe/', fn () => $this->coopetrolitos()->crear($nino, 'admin'));

        $this->coopetrolitos()->editar('1100050', [...$nino, 'documentoAsociado' => '2001'], 'admin');
        $fila = $this->coopetrolitos()->listar('Tomás')['filas'][0];
        $this->assertSame('2001', $fila['documento_asociado']);
        $this->assertSame('Jorge Iván Ramírez', $fila['nombre_asociado']);
        $this->assertSame('2015-06-10', $fila['fecha_nacimiento']);

        $this->coopetrolitos()->eliminar('1100050', 'admin');
        $this->assertSame(0, $this->coopetrolitos()->listar('Tomás')['total']);
    }
}
