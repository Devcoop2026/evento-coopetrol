<?php

namespace Tests\Feature;

use App\Aplicacion\Inscripciones\ArchivoRecibido;
use App\Aplicacion\Panel\DetalleInscripcion;
use App\Aplicacion\Panel\ExportarInscripciones;
use App\Aplicacion\Panel\ListarInscripciones;
use App\Aplicacion\Panel\ObtenerComprobante;
use App\Infraestructura\Configuracion\LectorConfiguracion;
use App\Models\Soporte;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Registro del pago: validaciones, comprobante, campos adicionales y pago en agencia. */
class PagosTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->configurarEvento(['inscripciones_desde' => '2026-10-01', 'inscripciones_hasta' => '2026-10-31', 'soporte_max_mb' => 1]);
    }

    private function detalle(string $referencia): array
    {
        return app(DetalleInscripcion::class)->ejecutar($this->idDe($referencia));
    }

    /** Pago en agencia (sin comprobante salvo que se envíe). */
    private function pagarEnAgencia(string $documento, ?string $referencia, array $extra = []): array
    {
        return $this->pagar($documento, $referencia, ['medioPago' => 'AGENCIA', 'cus' => null, 'agenciaPago' => 'BOGOTA', 'archivo' => null, ...$extra]);
    }

    public function test_validaciones_del_pago_sin_guardar_comprobante(): void
    {
        $r = $this->preinscribir('1001');
        $ref = $r['referencia'];

        $this->assertRechaza('/PDF, JPG o PNG/', fn () => $this->pagar('1001', $ref, ['archivo' => new ArchivoRecibido('virus.exe', "MZ\x90\x00programa")]));
        $this->assertRechaza('/CUS/', fn () => $this->pagar('1001', $ref, ['cus' => 'abc']));
        $this->assertRechaza('/Correo/', fn () => $this->pagar('1001', $ref, ['campos' => []]));
        $this->assertRechaza('/futura/', fn () => $this->pagar('1001', $ref, ['fechaPago' => '2026-10-06']));
        $this->assertRechaza('/tamaño máximo/', fn () => $this->pagar('1001', $ref, ['archivo' => new ArchivoRecibido('grande.pdf', '%PDF'.str_repeat('x', 2 * 1024 * 1024))]));
        $this->assertRechaza('/Adjunte el soporte/', fn () => $this->pagar('1001', $ref, ['archivo' => null]));
        $this->assertRechaza('/cómo realizó el pago/', fn () => $this->pagar('1001', $ref, ['medioPago' => 'OTRO']));

        $this->assertSame(0, $this->contarFilas('soportes_archivos'));
        $this->assertSame('PREINSCRITO', $this->consultar('1001')['estado']);
    }

    public function test_cus_no_se_puede_repetir_en_otra_inscripcion(): void
    {
        $a = $this->preinscribir('1001');
        $b = $this->preinscribir('1002');
        $this->pagar('1001', $a['referencia'], ['cus' => '77778888']);

        $this->assertRechaza('/CUS ya fue registrado/', fn () => $this->pagar('1002', $b['referencia'], ['cus' => '77778888']));
    }

    public function test_valor_pagado_distinto_genera_alerta(): void
    {
        $r = $this->preinscribir('1001');
        $this->pagar('1001', $r['referencia'], ['valorPagado' => '40.000']);

        $this->assertMatchesRegularExpression('/distinto del total/', app(ListarInscripciones::class)->ejecutar()[0]['alerta']);
    }

    public function test_pse_sin_banco_y_detalle_sin_nombre_interno_del_archivo(): void
    {
        $r = $this->preinscribir('1001');
        $this->pagar('1001', $r['referencia'], ['cus' => '12 345 678']);

        $soporte = $this->detalle($r['referencia'])['soportes'][0];
        $this->assertSame('12345678', $soporte['cus']);
        $this->assertSame('', $soporte['banco']);
        $this->assertTrue($soporte['tiene_archivo']);
        $this->assertArrayNotHasKey('archivo', $soporte);
    }

    public function test_campos_condicionales_del_formulario_de_soporte(): void
    {
        $this->configurarEvento(campos: LectorConfiguracion::json(base_path('data/formulario_soporte.json')));
        $r = $this->preinscribir('1001');
        $ref = $r['referencia'];
        $base = ['celular' => '3001234567', 'correo' => 'ana@correo.com', 'vehiculo' => 'No', 'discapacidad' => 'No'];

        $this->assertRechaza('/Placa del vehículo/', fn () => $this->pagar('1001', $ref, ['campos' => [...$base, 'vehiculo' => 'Sí']]));
        $this->assertRechaza('/Placa del vehículo.*formato/', fn () => $this->pagar('1001', $ref, ['campos' => [...$base, 'vehiculo' => 'Sí', 'placa' => '12ABC']]));
        $this->assertRechaza('/tipo de discapacidad/', fn () => $this->pagar('1001', $ref, ['campos' => [...$base, 'discapacidad' => 'Sí']]));
        $sinDiscapacidad = $base;
        unset($sinDiscapacidad['discapacidad']);
        $this->assertRechaza('/discapacidad/', fn () => $this->pagar('1001', $ref, ['campos' => $sinDiscapacidad]));
        $this->assertRechaza('/Celular/', fn () => $this->pagar('1001', $ref, ['campos' => [...$base, 'celular' => '300abc4567']]));

        $this->pagar('1001', $ref, ['campos' => [...$base, 'celular' => '300 123 4567', 'vehiculo' => 'Sí', 'placa' => 'abc 12d',
            'discapacidad' => 'Sí', 'tipo_discapacidad' => 'Titular: movilidad reducida', 'placaIgnorada' => 'XYZ999']]);
        $campos = $this->detalle($ref)['soportes'][0]['campos'];
        $this->assertSame('ABC12D', $campos['placa']);
        $this->assertSame('3001234567', $campos['celular']);
        $this->assertSame('Titular: movilidad reducida', $campos['tipo_discapacidad']);
        $this->assertArrayNotHasKey('placaIgnorada', $campos);

        // Cuando no aplican, la placa y el tipo de discapacidad no se guardan.
        $otra = $this->preinscribir('1002');
        $this->pagar('1002', $otra['referencia'], ['campos' => [...$base, 'placa' => 'ABC123', 'tipo_discapacidad' => 'Ninguna']]);
        $campos = $this->detalle($otra['referencia'])['soportes'][0]['campos'];
        $this->assertArrayNotHasKey('placa', $campos);
        $this->assertArrayNotHasKey('tipo_discapacidad', $campos);
    }

    public function test_pago_en_agencia_sin_comprobante(): void
    {
        $r = $this->preinscribir('1001');
        $ref = $r['referencia'];

        $this->assertRechaza('/agencia donde realizó el pago/', fn () => $this->pagarEnAgencia('1001', $ref, ['agenciaPago' => 'MARTE']));

        $this->pagarEnAgencia('1001', $ref, ['recibo' => 'rc 12345']);
        $soporte = $this->detalle($ref)['soportes'][0];
        $this->assertSame('AGENCIA', $soporte['medio_pago']);
        $this->assertSame('', $soporte['cus']);
        $this->assertSame('BOGOTA', $soporte['agencia_pago']);
        $this->assertSame('RC12345', $soporte['recibo']);
        $this->assertFalse($soporte['tiene_archivo']);
        $this->assertSame('', Soporte::query()->find($soporte['id'])->archivo);
        $this->assertSame(0, $this->contarFilas('soportes_archivos'));
        $this->assertRechaza('/sin comprobante/', fn () => app(ObtenerComprobante::class)->ejecutar($soporte['id']));

        $publico = $this->consultar('1001')['soporte'];
        $this->assertSame('AGENCIA', $publico['medioPago']);
        $this->assertFalse($publico['conArchivo']);

        // El recibo no se puede repetir; el comprobante es opcional pero se guarda si llega.
        $otra = $this->preinscribir('1002');
        $this->assertRechaza('/recibo ya fue registrado/', fn () => $this->pagarEnAgencia('1002', $otra['referencia'], ['recibo' => 'RC12345']));
        $this->pagarEnAgencia('1002', $otra['referencia'], ['recibo' => 'RC12346', 'archivo' => new ArchivoRecibido('recibo.jpg', "\xFF\xD8\xFFfoto")]);
        $this->assertSame(1, $this->contarFilas('soportes_archivos'));
        $this->assertTrue($this->consultar('1002')['soporte']['conArchivo']);

        $this->assertMatchesRegularExpression('/Agencia \/ efectivo;;;BOGOTA;RC12345;No/', app(ExportarInscripciones::class)->ejecutar());
    }
}
