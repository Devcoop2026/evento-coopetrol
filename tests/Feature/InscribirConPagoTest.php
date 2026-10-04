<?php

namespace Tests\Feature;

use App\Aplicacion\Inscripciones\ArchivoRecibido;
use App\Aplicacion\Inscripciones\InscribirConPago;
use App\Aplicacion\Panel\ConsultarCupos;
use App\Aplicacion\Panel\DetalleInscripcion;
use App\Dominio\Seguridad\HuellaFecha;
use App\Models\Asociado;
use App\Models\Tarifa;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Módulo "Inscripción y pago en agencia": inscribirse y registrar el pago en un solo paso, todo o nada. */
class InscribirConPagoTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->configurarEvento(['inscripciones_desde' => '2026-10-01', 'inscripciones_hasta' => '2026-10-31', 'soporte_max_mb' => 1]);
    }

    private function inscribirConPago(array $credenciales, array $extra = []): array
    {
        return app(InscribirConPago::class)->ejecutar([
            ...$credenciales,
            'medioPago' => 'AGENCIA',
            'agenciaPago' => 'BOGOTA',
            'recibo' => 'RC-900',
            'fechaPago' => '2026-10-05',
            'valorPagado' => '43500',
            'campos' => ['correo' => 'asociado@correo.com'],
            'autorizaDatos' => true,
            ...$extra,
        ], '10.0.0.9');
    }

    public function test_inscribe_y_registra_el_pago_en_un_paso(): void
    {
        $r = $this->inscribirConPago(self::credenciales('1001'), ['acompanantes' => [['documento' => '90555', 'nombre' => 'Invitado Prueba']]]);

        $this->assertMatchesRegularExpression('/^EVT26-\d{6}$/', $r['referencia']);
        $this->assertSame('EN_REVISION', $r['estado']);
        $this->assertFalse($r['editable']);
        $this->assertSame('AGENCIA', $r['soporte']['medioPago']);
        $this->assertSame('BOGOTA', $r['soporte']['agenciaPago']);
        $this->assertFalse($r['soporte']['conArchivo']);
        $this->assertSame(1, $this->cupos('BOGOTA')['ocupados']);
        $this->assertSame(1, $this->cupos('BOGOTA')['invitados']);

        $detalle = app(DetalleInscripcion::class)->ejecutar($this->idDe($r['referencia']));
        $this->assertSame('10.0.0.9', $detalle['autorizacion_ip']);
        $this->assertSame('v-prueba', $detalle['autorizacion_version']);
        $this->assertMatchesRegularExpression('/distinto del total/', $detalle['soportes'][0]['alerta']);
    }

    public function test_todo_o_nada(): void
    {
        $credenciales = self::credenciales('1001');
        $this->assertRechaza('/agencia/', fn () => $this->inscribirConPago($credenciales, ['agenciaPago' => 'NO-EXISTE']));
        $this->assertRechaza('/futura/', fn () => $this->inscribirConPago($credenciales, ['fechaPago' => '2030-01-01']));
        $this->assertRechaza('/Correo/', fn () => $this->inscribirConPago($credenciales, ['campos' => []]));
        $this->assertRechaza('/tratamiento de datos/', fn () => $this->inscribirConPago($credenciales, ['autorizaDatos' => false]));
        $this->assertSame(0, $this->contarFilas('inscripciones'));

        // Sin cupo no queda ni la inscripción ni el comprobante.
        $this->ajustarCupos('BOGOTA', 0);
        $this->assertRechaza('/superado el límite/', fn () => $this->inscribirConPago($credenciales, ['archivo' => new ArchivoRecibido('recibo.pdf', self::PDF)]));
        $this->assertSame(0, $this->contarFilas('inscripciones'));
        $this->assertSame(0, $this->contarFilas('soportes_archivos'));

        $this->ajustarCupos('BOGOTA', 100);
        $this->assertSame('EN_REVISION', $this->inscribirConPago($credenciales)['estado']);
        $this->assertRechaza('/recibo ya fue registrado/', fn () => $this->inscribirConPago(self::credenciales('1002')));
        $this->assertRechaza('/preinscripción activa/', fn () => $this->inscribirConPago($credenciales, ['recibo' => 'RC-901']));
        $this->assertSame(1, $this->contarFilas('inscripciones'));

        $r = $this->inscribirConPago(self::credenciales('1002'), ['recibo' => 'RC-902', 'archivo' => new ArchivoRecibido('recibo.png', self::PNG)]);
        $this->assertTrue($r['soporte']['conArchivo']);
        $this->assertSame(1, $this->contarFilas('soportes_archivos'));
        $this->assertSame($r['referencia'], $this->consultar('1002')['referencia']);
    }

    public function test_evento_compartido_por_varias_agencias(): void
    {
        $huella = app(HuellaFecha::class);
        foreach (['3301' => 'CARTAGENA', '3302' => 'PTO. MAMONAL', '3303' => 'PTO. MAMONAL'] as $documento => $agencia) {
            Asociado::query()->create([
                'documento' => $documento, 'nombre' => "Asociado Costa {$documento}", 'agencia' => $agencia, 'estado' => 'ACTIVO',
                'fecha_actualizacion' => '2026-08-01', 'expedicion_hmac' => $huella->calcular('2000-01-01'),
            ]);
        }
        Tarifa::query()->whereKey('CARTAGENA Y MAMONAL')->update(['cupos' => 2]);
        $credenciales = fn (string $doc) => ['documento' => $doc, 'fechaExpedicion' => '2000-01-01'];

        $r = $this->inscribirConPago($credenciales('3301'), ['agenciaPago' => 'PTO. MAMONAL']);
        $this->assertSame('CARTAGENA Y MAMONAL', $r['agencia']);
        $this->assertSame('CARTAGENA', $r['agenciaAsociado']);
        $this->assertSame('PTO. MAMONAL', $r['soporte']['agenciaPago']);

        $this->inscribirConPago($credenciales('3302'), ['agenciaPago' => 'CARTAGENA', 'recibo' => 'RC-777']);
        $this->assertSame(2, $this->cupos('CARTAGENA Y MAMONAL')['ocupados']);

        $this->assertRechaza('/superado el límite de cupos disponibles para el evento de CARTAGENA Y MAMONAL/',
            fn () => $this->inscribirConPago($credenciales('3303'), ['recibo' => 'RC-778']));

        $agencias = array_column(app(ConsultarCupos::class)->ejecutar(), 'agencia');
        $this->assertNotContains('CARTAGENA', $agencias);
        $this->assertNotContains('PTO. MAMONAL', $agencias);
    }
}
