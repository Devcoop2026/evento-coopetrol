<?php

namespace Tests\Feature;

use App\Aplicacion\Inscripciones\CancelarInscripcion;
use App\Aplicacion\Inscripciones\ConsultarInscripcion;
use App\Aplicacion\Inscripciones\ModificarInscripcion;
use App\Aplicacion\Panel\DetalleInscripcion;
use App\Dominio\Compartido\ErrorValidacion;
use Tests\Concerns\ConDatosDePrueba;
use Tests\Concerns\OperaInscripciones;
use Tests\TestCase;

/** Preinscripción, cupos, modificación, cancelación y consulta (hoy = 05/10/2026, periodo del 01 al 31/10). */
class InscripcionesTest extends TestCase
{
    use ConDatosDePrueba, OperaInscripciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-10-05T15:00:00Z');
        $this->configurarEvento(['inscripciones_desde' => '2026-10-01', 'inscripciones_hasta' => '2026-10-31', 'soporte_max_mb' => 1]);
    }

    private function modificar(string $documento, array $acompanantes, array $extra = []): array
    {
        return app(ModificarInscripcion::class)->ejecutar([...self::credenciales($documento), 'acompanantes' => $acompanantes, ...$extra]);
    }

    public function test_preinscripcion_genera_referencia_y_no_ocupa_cupo_hasta_el_pago(): void
    {
        $r = $this->preinscribir('2001', [
            ['documento' => '2002', 'nombre' => 'Paola Andrea Díaz'],
            ['documento' => '90777', 'nombre' => 'Invitado Prueba'],
        ]);

        $this->assertMatchesRegularExpression('/^EVT26-\d{6}$/', $r['referencia']);
        $this->assertSame('PREINSCRITO', $r['estado']);
        $this->assertSame(176000, $r['total']);
        $this->assertSame(0, $this->cupos('ORITO')['ocupados']);
        $this->assertSame(2, $this->cupos('ORITO')['pendientes']);

        $this->pagar('2001', $r['referencia']);
        $this->assertSame(2, $this->cupos('ORITO')['ocupados']);
        $this->assertSame(1, $this->cupos('ORITO')['invitados']);
    }

    public function test_fuera_del_periodo_de_inscripciones(): void
    {
        $this->configurarEvento(['inscripciones_desde' => '2026-10-10', 'inscripciones_hasta' => '2026-10-31']);

        $this->assertRechaza('/abren el 10\/10\/2026/', fn () => $this->preinscribir('1001'));
    }

    public function test_periodo_sin_validar_permite_inscribirse(): void
    {
        $this->configurarEvento(['validar_periodo_inscripcion' => false, 'inscripciones_desde' => '2026-12-01']);

        $this->assertSame('PREINSCRITO', $this->preinscribir('1001')['estado']);
    }

    public function test_no_permite_inscribir_dos_veces_a_la_misma_persona(): void
    {
        $primera = $this->preinscribir('1001', [['documento' => '90555', 'nombre' => 'Invitado Prueba']]);

        $error = $this->assertRechaza('/'.$primera['referencia'].'/', fn () => $this->preinscribir('1001'));
        $this->assertSame($primera['referencia'], $error->datos['referenciaExistente']);

        $this->assertRechaza('/ya está registrado/', fn () => $this->preinscribir('1002', [['documento' => '1001', 'nombre' => 'Ana María Torres']]));
        $this->assertRechaza('/ya está registrado/', fn () => $this->preinscribir('1002', [['documento' => '90555', 'nombre' => 'Invitado Prueba']]));
    }

    public function test_preinscripcion_respeta_el_cupo_del_evento(): void
    {
        $this->ajustarCupos('ORITO', 1);

        $this->assertRechaza('/superado el límite de cupos disponibles: en el evento de ORITO quedan 1/',
            fn () => $this->preinscribir('2001', [['documento' => '2002', 'nombre' => 'Paola Andrea Díaz']]));

        // Los invitados no ocupan cupo.
        $r = $this->preinscribir('2001', [
            ['documento' => '90777', 'nombre' => 'Invitado Uno'],
            ['documento' => '90778', 'nombre' => 'Invitado Dos'],
        ]);
        $this->pagar('2001', $r['referencia']);

        $this->assertRechaza('/Lo sentimos, se ha superado el límite/', fn () => $this->preinscribir('2002'));
    }

    public function test_el_cupo_se_ocupa_al_registrar_el_pago(): void
    {
        $this->ajustarCupos('ORITO', 1);
        $a = $this->preinscribir('2001');
        $b = $this->preinscribir('2002');
        $this->assertSame(0, $this->cupos('ORITO')['ocupados']);

        $this->pagar('2001', $a['referencia']);
        $this->assertRechaza('/superado el límite/', fn () => $this->pagar('2002', $b['referencia'], ['cus' => '55556666']));
        $this->assertSame('PREINSCRITO', $this->consultar('2002')['estado']);

        // Rechazar el pago de A libera el cupo.
        $this->revisar($a['referencia'], 'RECHAZAR', 'Ilegible');
        $this->assertSame(0, $this->cupos('ORITO')['ocupados']);

        $this->assertSame('EN_REVISION', $this->pagar('2002', $b['referencia'], ['cus' => '55556666'])['estado']);
    }

    public function test_asistir_al_evento_de_otra_agencia(): void
    {
        $r = $this->preinscribir('1001', [['documento' => '90777', 'nombre' => 'Invitado Prueba']], ['agenciaEvento' => 'ORITO']);

        $this->assertSame('ORITO', $r['agencia']);
        $this->assertSame('BOGOTA', $r['agenciaAsociado']);
        $this->assertSame(143000, $r['total']);
        $this->assertSame(1, $this->cupos('ORITO')['pendientes']);
        $this->assertSame(0, $this->cupos('BOGOTA')['pendientes']);

        $this->assertRechaza('/No existe un evento/', fn () => $this->preinscribir('1002', [], ['agenciaEvento' => 'MARTE']));

        $m = $this->modificar('1001', [], ['agenciaEvento' => 'CALI']);
        $this->assertSame('CALI', $m['agencia']);
        $this->assertSame(42000, $m['total']);
    }

    public function test_ajuste_de_cupos_desde_el_panel(): void
    {
        $r = $this->preinscribir('2001', [['documento' => '2002', 'nombre' => 'Paola Andrea Díaz']]);
        $this->pagar('2001', $r['referencia']);

        $this->assertRechaza('/2 cupos ocupados/', fn () => $this->ajustarCupos('ORITO', 1));
        $this->assertRechaza('/entero/', fn () => $this->ajustarCupos('ORITO', -3));

        $c = $this->ajustarCupos('ORITO', 2);
        $this->assertSame(2, $c['cupos']);
        $this->assertSame(115, $c['cupos_excel']);
        $this->assertSame(0, $c['disponibles']);
        $this->assertRechaza('/superado el límite/', fn () => $this->preinscribir('3001', [], ['agenciaEvento' => 'ORITO']));

        $c = $this->ajustarCupos('ORITO', '');
        $this->assertSame(115, $c['cupos']);
        $this->assertNull($c['cupos_ajustados']);
    }

    public function test_cancelar_permite_inscribirse_de_nuevo(): void
    {
        $this->preinscribir('1001');

        $this->assertSame('CANCELADO', app(CancelarInscripcion::class)->ejecutar(self::credenciales('1001'))['estado']);
        $this->assertSame('PREINSCRITO', $this->preinscribir('1001')['estado']);
    }

    public function test_consulta_exige_identidad_y_referencia_propia(): void
    {
        $r = $this->preinscribir('1001');

        $this->assertRechaza('/no coinciden/', fn () => app(ConsultarInscripcion::class)
            ->ejecutar(['documento' => '1001', 'fechaExpedicion' => '2000-01-01', 'referencia' => $r['referencia']]));
        $this->assertRechaza('/No encontramos/', fn () => $this->consultar('1002', $r['referencia']));
    }

    public function test_modificar_recalcula_y_libera_acompanantes(): void
    {
        $this->preinscribir('1001', [['documento' => '90555', 'nombre' => 'Invitado Prueba']]);

        $m = $this->modificar('1001', [
            ['documento' => '1002', 'nombre' => 'Carlos Pérez Rojas'],
            ['documento' => '90556', 'nombre' => 'Invitado Nuevo'],
        ]);
        $this->assertSame(43500 + 43500 + 145000, $m['total']);
        $this->assertSame(['TITULAR', 'ASOCIADO', 'INVITADO'], array_column($m['personas'], 'tipo'));

        // 90555 quedó libre.
        $this->assertSame('PREINSCRITO', $this->preinscribir('2001', [['documento' => '90555', 'nombre' => 'Invitado Prueba']])['estado']);

        // La modificación también valida el cupo.
        $this->ajustarCupos('BOGOTA', 2);
        $this->assertRechaza('/cupo/', fn () => $this->modificar('1001', [
            ['documento' => '1002', 'nombre' => 'Carlos Pérez Rojas'],
            ['documento' => '1003', 'nombre' => 'Luisa Fernanda Gómez'],
        ]));
    }

    public function test_con_soporte_cargado_no_se_puede_modificar_ni_pagar_otra_vez(): void
    {
        $r = $this->preinscribir('1001');

        $this->assertSame('EN_REVISION', $this->pagar('1001', $r['referencia'])['estado']);
        $this->assertSame(1, $this->contarFilas('soportes_archivos'));
        $this->assertRechaza('/en revision/', fn () => $this->modificar('1001', []));
        $this->assertRechaza('/en revision/', fn () => $this->pagar('1001', $r['referencia']));
    }

    public function test_coopetrolito_ocupa_cupo(): void
    {
        $r = $this->preinscribir('1001', [
            ['documento' => '1100001', 'nombre' => 'Mariana Torres', 'tipo' => 'COOPETROLITO'],
            ['documento' => '90777', 'nombre' => 'Invitado Prueba'],
        ]);
        $this->pagar('1001', $r['referencia']);

        $this->assertSame(2, $this->cupos('BOGOTA')['ocupados']);
        $this->assertSame(1, $this->cupos('BOGOTA')['invitados']);
    }

    public function test_autorizacion_de_tratamiento_de_datos(): void
    {
        $this->assertRechaza('/tratamiento de datos/', fn () => $this->preinscribir('1001', [], ['autorizaDatos' => null]));

        $r = $this->preinscribir('1001');
        $detalle = app(DetalleInscripcion::class)->ejecutar($this->idDe($r['referencia']));
        $this->assertSame('v-prueba', $detalle['autorizacion_version']);
        $this->assertSame('2026-10-05T15:00:00.000Z', $detalle['autorizacion_en']);
        $this->assertSame('10.0.0.1', $detalle['autorizacion_ip']);

        $this->assertRechaza('/tratamiento de datos/', fn () => $this->pagar('1001', $r['referencia'], ['autorizaDatos' => false]));
    }

    public function test_consulta_sin_referencia_toma_la_inscripcion_vigente(): void
    {
        $this->assertRechaza('/No encontramos una inscripción para su documento/', fn () => $this->consultar('1001'));

        $cancelada = $this->preinscribir('1001');
        app(CancelarInscripcion::class)->ejecutar(self::credenciales('1001'));
        $vigente = $this->preinscribir('1001');
        $this->assertNotSame($cancelada['referencia'], $vigente['referencia']);

        $this->assertSame($vigente['referencia'], $this->consultar('1001')['referencia']);
        $this->assertSame('EN_REVISION', $this->pagar('1001', null)['estado']);

        $this->expectException(ErrorValidacion::class);
        app(ConsultarInscripcion::class)->ejecutar(['documento' => '1001', 'fechaExpedicion' => '2008-03-15']);
    }
}
