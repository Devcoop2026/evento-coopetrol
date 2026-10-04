<?php

namespace Tests\Feature;

use App\Aplicacion\Bases\CargarBase;
use App\Aplicacion\Bases\DefinicionesBase;
use App\Aplicacion\Simulacion\IdentificarAsociado;
use App\Aplicacion\Simulacion\SimularInscripcion;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Seguridad\HuellaFecha;
use App\Models\Asociado;
use App\Models\Coopetrolito;
use Tests\Concerns\ConDatosDePrueba;
use Tests\TestCase;

/** Carga masiva de las bases de asociados y Coopetrolitos (Excel y CSV), con vista previa y confirmación. */
class BasesTest extends TestCase
{
    use ConDatosDePrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarTarifas(); // sin asociados
        $this->fijarAhora('2026-09-30T15:00:00Z');
        $this->configurarEvento();
    }

    private function cargar(string $tipo, string $contenido, bool $confirmar = false): array
    {
        return app(CargarBase::class)->ejecutar($tipo, $contenido, $confirmar, "{$tipo}.archivo", 'tesoreria');
    }

    private function excel(): string
    {
        return file_get_contents(base_path('tests/Fixtures/asociados.xlsx'));
    }

    private function assertRechaza(string $patron, callable $accion): void
    {
        try {
            $accion();
        } catch (ErrorValidacion $error) {
            $this->assertMatchesRegularExpression($patron, $error->getMessage());

            return;
        }
        $this->fail("Se esperaba un error de validación que cumpliera {$patron}.");
    }

    public function test_vista_previa_del_excel_no_guarda(): void
    {
        $r = $this->cargar('asociados', $this->excel());

        $this->assertSame(3, $r['registros']);
        $this->assertSame(2, $r['activos']);
        $this->assertSame(2, $r['omitidas']);
        $this->assertStringContainsString('MARTE', implode("\n", $r['errores']));
        $this->assertStringContainsString('repetido', implode("\n", $r['errores']));
        $this->assertMatchesRegularExpression('/1 asociado\(s\) sin fecha de expedición/', implode("\n", $r['advertencias']));
        $this->assertFalse($r['guardado']);
        $this->assertSame(0, Asociado::query()->count());
    }

    public function test_confirmar_guarda_la_base_sin_fechas_de_expedicion_en_claro(): void
    {
        $this->cargar('asociados', $this->excel(), confirmar: true);

        $fila = Asociado::query()->find('5550002');
        $this->assertSame('Prueba Dos', $fila->nombre);
        $this->assertSame('2026-06-01', substr((string) $fila->fecha_actualizacion, 0, 10));
        $this->assertSame(app(HuellaFecha::class)->calcular('1999-08-15'), $fila->expedicion_hmac);
        foreach ($fila->getAttributes() as $valor) {
            $this->assertStringNotContainsString('1999-08-15', (string) $valor);
        }

        $this->assertSame('5550002', app(IdentificarAsociado::class)->ejecutar('5550002', '1999-08-15')->asociado->documento);
        $this->assertRechaza('/no coinciden/', fn () => app(IdentificarAsociado::class)->ejecutar('5550002', '1999-08-16'));

        $estado = app(CargarBase::class)->estado()['asociados'];
        $this->assertSame(3, $estado['total']);
        $this->assertSame('tesoreria', $estado['ultimaCarga']['usuario']);
    }

    public function test_csv_en_windows_1252_con_fechas_invalidas(): void
    {
        $csv = mb_convert_encoding(
            "Cédula;Nombre;Agencia;Asociado;Última actualización de datos;Fecha expedición\r\n"
            ."777;Ana Pérez;BOGOTA;SI;01/06/2026;10/10/2000\r\n"
            ."888;Luis;BOGOTA;SI;31/02/2026;10/10/2000\r\n", 'Windows-1252', 'UTF-8');

        $r = $this->cargar('asociados', $csv);

        $this->assertSame(1, $r['registros']);
        $this->assertMatchesRegularExpression('/Fila 3: fecha no válida/', $r['errores'][0]);
    }

    public function test_archivos_no_validos(): void
    {
        $this->assertRechaza('/Faltan columnas: cedula/', fn () => $this->cargar('asociados', "Nombre;Agencia\nAna Pérez;BOGOTA\n"));
        $this->assertRechaza('/vacío/', fn () => $this->cargar('asociados', ''));
        $this->assertRechaza('/./', fn () => $this->cargar('asociados', "PK\x03\x04\x01\x02\x03"));
        $this->assertRechaza('/Tipo de base/', fn () => $this->cargar('otra', "a;b\n1;2\n"));
    }

    public function test_coopetrolitos_en_csv_con_comas(): void
    {
        $this->cargar('asociados', $this->excel(), confirmar: true);

        $r = $this->cargar('coopetrolitos', "Documento,Nombre,Cedula asociado\n1100009,Hijo Prueba,5550001\n1100010,Otro Hijo,999\n", confirmar: true);
        $this->assertSame(2, $r['registros']);
        $this->assertMatchesRegularExpression('/1 Coopetrolito/', implode("\n", $r['advertencias']));

        $simulacion = app(SimularInscripcion::class)->ejecutar([
            'documento' => '5550001', 'fechaExpedicion' => '2001-05-20',
            'acompanantes' => [['documento' => '1100009', 'nombre' => 'Hijo Prueba', 'tipo' => 'COOPETROLITO']],
        ])->aArreglo();
        $this->assertSame('COOPETROLITO', $simulacion['acompanantes'][0]['tipo']);
    }

    public function test_documento_y_nombre_no_validos_indican_la_fila(): void
    {
        $r = $this->cargar('asociados', "Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion\n"
            ."AB123;Ana Pérez;BOGOTA;SI;;\n999;Luis 2 Gómez;BOGOTA;SI;;\n1000;Válido Uno;BOGOTA;SI;;\n");

        $this->assertSame(1, $r['registros']);
        $this->assertSame('Fila 2: el documento "AB123" solo debe contener números', $r['errores'][0]);
        $this->assertSame('Fila 3: el nombre "Luis 2 Gómez" solo debe contener letras y espacios', $r['errores'][1]);
    }

    public function test_fecha_de_nacimiento_en_el_formato_nuevo(): void
    {
        $r = $this->cargar('asociados', "Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion;fecha_nacimiento\n"
            ."7001;Ana Pérez;BOGOTA;SI;01/06/2026;10/10/2000;20/05/1985\n"
            ."7002;Luis Gómez;BOGOTA;SI;01/06/2026;10/10/2000;\n"
            ."7003;Eva Ruiz;BOGOTA;SI;01/06/2026;10/10/2000;30/02/1990\n"
            ."7004;Juan Díaz;BOGOTA;SI;01/06/2026;10/10/2000;01/01/2999\n", confirmar: true);

        $this->assertSame(2, $r['registros']);
        $this->assertMatchesRegularExpression('/Fila 4: fecha no válida/', $r['errores'][0]);
        $this->assertSame('Fila 5: la fecha de nacimiento 2999-01-01 no es válida', $r['errores'][1]);
        $this->assertSame('1985-05-20', substr((string) Asociado::query()->find('7001')->fecha_nacimiento, 0, 10));
        $this->assertNull(Asociado::query()->find('7002')->fecha_nacimiento);

        $this->cargar('coopetrolitos', "Documento;Nombre;Cedula asociado;fecha_nacimiento\n1100020;Niña Pérez;7001;10/06/2015\n", confirmar: true);
        $this->assertSame('2015-06-10', substr((string) Coopetrolito::query()->find('1100020')->fecha_nacimiento, 0, 10));
    }

    public function test_plantillas_incluyen_las_columnas_nuevas(): void
    {
        $this->assertStringContainsString('actualizacion_datos;fecha_expedicion;fecha_nacimiento', DefinicionesBase::plantilla('asociados'));
        $this->assertStringContainsString('Cedula asociado;fecha_nacimiento', DefinicionesBase::plantilla('coopetrolitos'));
    }

    public function test_resumen_de_agencias_no_reconocidas(): void
    {
        $r = $this->cargar('asociados', "Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion\n"
            ."8001;Ana Pérez;PTO. LUNA;SI;;\n8002;Luis Gómez;PTO. LUNA;SI;;\n8003;Eva Ruiz;MARTE;SI;;\n8004;Juan Díaz;PTO. MAMONAL;SI;;\n");

        $this->assertSame(1, $r['registros']);
        $this->assertMatchesRegularExpression('/Agencias no reconocidas: PTO\. LUNA \(2\), MARTE \(1\)/', implode("\n", $r['advertencias']));
    }
}
