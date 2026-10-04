<?php

namespace Tests\Feature;

use App\Aplicacion\Inscripciones\CancelarInscripcion;
use App\Aplicacion\Panel\DetalleInscripcion;
use App\Aplicacion\Panel\ExportarInscripciones;
use App\Dominio\Compartido\ErrorValidacion;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Revisión del pago en el panel (aprobar, rechazar, anular) y exportación a CSV. */
class RevisionExportacionTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->configurarEvento(['inscripciones_desde' => '2026-10-01', 'inscripciones_hasta' => '2026-10-31', 'soporte_max_mb' => 1]);
    }

    public function test_rechazo_nuevo_pago_y_aprobacion(): void
    {
        $r = $this->preinscribir('1001');
        $ref = $r['referencia'];
        $this->pagar('1001', $ref, ['cus' => '44445555']);

        $this->assertRechaza('/motivo/', fn () => $this->revisar($ref, 'RECHAZAR'));
        $this->assertSame('RECHAZADO', $this->revisar($ref, 'RECHAZAR', 'Soporte ilegible')['estado']);
        $consulta = $this->consultar('1001');
        $this->assertSame('RECHAZADO', $consulta['estado']);
        $this->assertSame('Soporte ilegible', $consulta['motivo']);

        // La misma inscripción puede volver a usar su CUS.
        $this->assertSame('EN_REVISION', $this->pagar('1001', $ref, ['cus' => '44445555'])['estado']);
        $this->assertSame('CONFIRMADO', $this->revisar($ref, 'APROBAR')['estado']);

        $this->expectException(ErrorValidacion::class);
        app(CancelarInscripcion::class)->ejecutar(self::credenciales('1001'));
    }

    public function test_anular_libera_el_cupo(): void
    {
        $r = $this->preinscribir('1001');
        $this->pagar('1001', $r['referencia']);
        $this->assertSame(1, $this->cupos('BOGOTA')['ocupados']);

        $this->revisar($r['referencia'], 'ANULAR', 'Solicitud del asociado');

        $this->assertSame(0, $this->cupos('BOGOTA')['ocupados']);
        $this->assertSame('ANULADO', $this->consultar('1001')['estado']);
    }

    public function test_exportacion_neutraliza_formulas(): void
    {
        $this->assertRechaza('/solo debe contener letras/',
            fn () => $this->preinscribir('1001', [['documento' => '90777', 'nombre' => '=HYPERLINK("x")']]));

        $r = $this->preinscribir('1001', [['documento' => '90777', 'nombre' => 'Ana Pérez']]);
        $this->pagar('1001', $r['referencia'], ['campos' => ['correo' => 'ana@correo.com']]);
        $this->revisar($r['referencia'], 'RECHAZAR', '=HYPERLINK("x")');

        $csv = app(ExportarInscripciones::class)->ejecutar();
        $this->assertStringStartsWith("\u{FEFF}Referencia;", $csv);
        $this->assertStringContainsString(';Correo;', $csv);
        $this->assertStringContainsString('ana@correo.com', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertStringContainsString('v-prueba', $csv); // versión de la autorización de datos
    }

    public function test_autorizacion_de_uso_de_imagen(): void
    {
        $sin = $this->preinscribir('1001');
        $con = $this->preinscribir('1002', [], ['autorizaImagen' => true]);
        $texto = $this->preinscribir('2001', [], ['autorizaImagen' => 'si']);

        $imagen = fn (array $r) => app(DetalleInscripcion::class)->ejecutar($this->idDe($r['referencia']))['autorizacion_imagen'];
        $this->assertFalse($imagen($sin));
        $this->assertTrue($imagen($con));
        $this->assertFalse($imagen($texto));

        $csv = app(ExportarInscripciones::class)->ejecutar();
        $this->assertStringContainsString('Autoriza uso de imagen', $csv);
        $this->assertMatchesRegularExpression('/'.$con['referencia'].';.*;Sí;/', $csv);
    }
}
