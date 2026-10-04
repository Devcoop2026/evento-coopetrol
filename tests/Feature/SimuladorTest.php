<?php

namespace Tests\Feature;

use App\Aplicacion\Inscripciones\ConsultarInscripcion;
use App\Aplicacion\Simulacion\SimularInscripcion;
use App\Dominio\Compartido\ErrorValidacion;
use App\Models\Asociado;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ConDatosDePrueba;
use Tests\TestCase;

/** Simulador: identificación del titular y liquidación con los asociados ficticios (hoy = 29/09/2026). */
class SimuladorTest extends TestCase
{
    use ConDatosDePrueba;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cargarDatosPrueba();
        $this->fijarAhora('2026-09-29T15:00:00Z');
        $this->configurarEvento();
    }

    private function simular(string $documento, array $acompanantes = [], array $extra = []): array
    {
        return app(SimularInscripcion::class)->ejecutar([
            'documento' => $documento,
            'fechaExpedicion' => self::expedicion(trim(str_replace('.', '', $documento))),
            'acompanantes' => $acompanantes,
            ...$extra,
        ])->aArreglo();
    }

    /** Ejecuta la simulación y exige un ErrorValidacion cuyo mensaje cumpla el patrón. */
    private function assertRechaza(string $patron, callable $accion): ErrorValidacion
    {
        try {
            $accion();
        } catch (ErrorValidacion $error) {
            $this->assertMatchesRegularExpression($patron, $error->getMessage());

            return $error;
        }
        $this->fail("Se esperaba un error de validación que cumpliera {$patron}.");
    }

    public function test_titular_solo_paga_tarifa_de_asociado(): void
    {
        $r = $this->simular('1001');

        $this->assertSame('SOLO', $r['modalidad']);
        $this->assertSame(43500, $r['resumen']['total']);
    }

    public function test_acompanantes_asociado_e_invitado_con_tarifas_de_orito(): void
    {
        $r = $this->simular('2001', [
            ['documento' => '2002', 'nombre' => 'Paola Andrea Díaz'],
            ['documento' => '90777', 'nombre' => 'Invitado Prueba'],
        ]);

        $this->assertSame([['ASOCIADO', 33000], ['INVITADO', 110000]], array_map(fn ($a) => [$a['tipo'], $a['valor']], $r['acompanantes']));
        $this->assertSame(176000, $r['resumen']['total']);
    }

    public function test_acompanante_asociado_paga_la_tarifa_del_evento_del_titular(): void
    {
        $r = $this->simular('3001', [['documento' => '1001', 'nombre' => 'Ana María Torres']]);

        $this->assertSame(42000, $r['acompanantes'][0]['valor']);
    }

    public function test_acompanante_inactivo_paga_como_invitado(): void
    {
        $r = $this->simular('1001', [['documento' => '9001', 'nombre' => 'Hernán Ortiz']]);

        $this->assertSame('INVITADO', $r['acompanantes'][0]['tipo']);
        $this->assertSame(145000, $r['acompanantes'][0]['valor']);
    }

    public function test_documento_del_titular_se_normaliza(): void
    {
        $r = app(SimularInscripcion::class)->ejecutar(['documento' => ' 1.001 ', 'fechaExpedicion' => self::expedicion('1001')])->aArreglo();

        $this->assertSame('1001', $r['asociado']['documento']);
    }

    public static function solicitudesInvalidas(): array
    {
        $acompanante = fn (string $doc) => ['documento' => $doc, 'nombre' => 'Persona Prueba'];

        return [
            'titular inexistente' => [['documento' => '0000', 'fechaExpedicion' => '2000-01-01']],
            'titular inactivo' => [['documento' => '9001', 'fechaExpedicion' => '2001-09-19']],
            'documento vacío' => [['documento' => '', 'fechaExpedicion' => '2008-03-14']],
            'acompañante sin documento' => [['documento' => '1001', 'fechaExpedicion' => '2008-03-14', 'acompanantes' => [$acompanante('')]]],
            'acompañante igual al titular' => [['documento' => '1001', 'fechaExpedicion' => '2008-03-14', 'acompanantes' => [$acompanante('1001')]]],
            'acompañantes repetidos' => [['documento' => '1001', 'fechaExpedicion' => '2008-03-14', 'acompanantes' => [$acompanante('90777'), $acompanante('90777')]]],
            'más de 5 acompañantes' => [['documento' => '1001', 'fechaExpedicion' => '2008-03-14',
                'acompanantes' => array_map(fn ($i) => $acompanante((string) (90770 + $i)), range(1, 6))]],
        ];
    }

    #[DataProvider('solicitudesInvalidas')]
    public function test_rechaza_solicitudes_invalidas(array $datos): void
    {
        $this->expectException(ErrorValidacion::class);

        app(SimularInscripcion::class)->ejecutar($datos);
    }

    public function test_datos_desactualizados_hace_mas_de_12_meses(): void
    {
        $this->assertRechaza('/12 meses/', fn () => $this->simular('4001'));
    }

    public function test_sin_actualizacion_de_datos(): void
    {
        $this->assertRechaza('/No registra actualización/', fn () => $this->simular('6001'));
    }

    public function test_limite_de_vigencia_de_la_actualizacion_de_datos(): void
    {
        Asociado::query()->whereKey('1001')->update(['fecha_actualizacion' => '2025-09-29']);
        $this->assertSame(43500, $this->simular('1001')['resumen']['total']);

        Asociado::query()->whereKey('1001')->update(['fecha_actualizacion' => '2025-09-28']);
        $this->assertRechaza('/12 meses/', fn () => $this->simular('1001'));
    }

    public function test_acompanante_con_datos_desactualizados_sigue_siendo_asociado(): void
    {
        $r = $this->simular('1001', [['documento' => '4001', 'nombre' => 'Mónica Herrera']]);

        $this->assertSame('ASOCIADO', $r['acompanantes'][0]['tipo']);
    }

    public function test_fecha_equivocada_y_documento_inexistente_dan_el_mismo_mensaje(): void
    {
        $this->assertRechaza('/no coinciden/', fn () => app(SimularInscripcion::class)->ejecutar(['documento' => '3001', 'fechaExpedicion' => '2011-08-03']));
        $this->assertRechaza('/no coinciden/', fn () => app(SimularInscripcion::class)->ejecutar(['documento' => '888888', 'fechaExpedicion' => '2011-08-02']));
    }

    public function test_exige_la_fecha_de_expedicion(): void
    {
        $this->assertRechaza('/fecha de expedición/', fn () => app(SimularInscripcion::class)->ejecutar(['documento' => '1001']));
    }

    public function test_bloquea_el_documento_tras_10_intentos_fallidos(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertRechaza('/no coinciden/', fn () => app(SimularInscripcion::class)->ejecutar(['documento' => '5001', 'fechaExpedicion' => '2000-01-01']));
        }

        // Ni siquiera con la fecha correcta mientras dure el bloqueo.
        $this->assertRechaza('/Demasiados intentos/', fn () => $this->simular('5001'));
    }

    public function test_acompanante_sin_nombre_completo(): void
    {
        $this->assertRechaza('/nombres y apellidos/', fn () => $this->simular('1001', [['documento' => '90777']]));
        $this->assertRechaza('/nombres y apellidos/', fn () => $this->simular('1001', [['documento' => '90777', 'nombre' => '  ']]));
    }

    public function test_coopetrolito_del_titular_paga_tarifa_de_asociado(): void
    {
        $r = $this->simular('1001', [['documento' => '1100001', 'nombre' => 'Mariana Torres', 'tipo' => 'COOPETROLITO']]);

        $this->assertSame('COOPETROLITO', $r['acompanantes'][0]['tipo']);
        $this->assertSame(43500, $r['acompanantes'][0]['valor']);
        $this->assertSame(1, $r['resumen']['coopetrolitos']);
        $this->assertSame(87000, $r['resumen']['total']);
    }

    public function test_coopetrolito_de_otro_asociado_o_desconocido_se_rechaza(): void
    {
        $this->assertRechaza('/no figura como Coopetrolito/',
            fn () => $this->simular('1001', [['documento' => '1100003', 'nombre' => 'Valentina Ramírez', 'tipo' => 'COOPETROLITO']]));
        $this->assertRechaza('/no figura como Coopetrolito/',
            fn () => $this->simular('1001', [['documento' => '90999', 'nombre' => 'Niño Desconocido', 'tipo' => 'COOPETROLITO']]));
    }

    public function test_coopetrolito_marcado_como_no_asociado_paga_como_invitado(): void
    {
        $r = $this->simular('1001', [['documento' => '1100001', 'nombre' => 'Mariana Torres', 'tipo' => 'NO_ASOCIADO']]);

        $this->assertSame('INVITADO', $r['acompanantes'][0]['tipo']);
    }

    public function test_asociado_marcado_como_coopetrolito_se_liquida_como_asociado(): void
    {
        $r = $this->simular('1001', [['documento' => '1002', 'nombre' => 'Carlos Pérez Rojas', 'tipo' => 'COOPETROLITO']]);

        $this->assertSame('ASOCIADO', $r['acompanantes'][0]['tipo']);
    }

    public function test_reglas_de_documento_y_nombre_del_acompanante(): void
    {
        $this->assertRechaza('/solo debe contener números/', fn () => $this->simular('1001', [['documento' => 'AB12345', 'nombre' => 'Ana Pérez']]));
        $this->assertRechaza('/nombres y apellidos/', fn () => $this->simular('1001', [['documento' => '12345678', 'nombre' => 'Ana']]));
        $this->assertRechaza('/solo debe contener letras/', fn () => $this->simular('1001', [['documento' => '12345678', 'nombre' => 'Ana P3rez']]));

        $r = $this->simular('1001', [['documento' => '12.345.678', 'nombre' => "María José O'Neill-Peña"]]);
        $this->assertSame('12345678', $r['acompanantes'][0]['documento']);
        $this->assertSame("María José O'Neill-Peña", $r['acompanantes'][0]['nombre']);
    }

    public function test_consulta_con_documento_no_numerico(): void
    {
        $this->assertRechaza('/solo debe contener números/',
            fn () => app(ConsultarInscripcion::class)->ejecutar(['documento' => '10A1', 'fechaExpedicion' => '2008-03-14']));
    }
}
