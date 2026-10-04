<?php

namespace Tests\Unit\Dominio;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Inscripcion\Acompanantes\ClasificadorAcompanantes;
use App\Dominio\Inscripcion\Acompanantes\ContextoAcompanante;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Padron\Asociado;
use App\Dominio\Padron\Coopetrolito;
use App\Dominio\Padron\Tarifa;
use PHPUnit\Framework\TestCase;

/** Clasificación de acompañantes (estrategias asociado, Coopetrolito, invitado) y cupos que ocupa un grupo. */
class AcompanantesTest extends TestCase
{
    private Tarifa $tarifa;

    protected function setUp(): void
    {
        $this->tarifa = new Tarifa('BOGOTA', 300, 145000, 43500);
    }

    private static function contexto(?string $solicitado = null, ?Asociado $registro = null, ?Coopetrolito $coopetrolito = null): ContextoAcompanante
    {
        return new ContextoAcompanante('5000', 'Acompañante 1', $solicitado, '1001', $registro, fn () => $coopetrolito);
    }

    public function test_asociado_activo_se_clasifica_como_asociado_con_tarifa_de_asociado(): void
    {
        $persona = (new ClasificadorAcompanantes)->clasificar(
            self::contexto(registro: new Asociado('5000', 'Ana Pérez', 'BOGOTA', 'ACTIVO')), $this->tarifa, 'Ana Pérez');

        $this->assertSame('ASOCIADO', $persona->tipo);
        $this->assertSame(43500, $persona->valor);
        $this->assertNull($persona->observacion);
    }

    public function test_coopetrolito_del_titular_se_clasifica_como_coopetrolito(): void
    {
        $persona = (new ClasificadorAcompanantes)->clasificar(
            self::contexto('COOPETROLITO', coopetrolito: new Coopetrolito('5000', '1001')), $this->tarifa, 'Hijo Torres');

        $this->assertSame('COOPETROLITO', $persona->tipo);
        $this->assertSame(43500, $persona->valor);
    }

    public function test_coopetrolito_de_otro_asociado_se_rechaza(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/no figura como Coopetrolito/');

        (new ClasificadorAcompanantes)->clasificar(
            self::contexto('COOPETROLITO', coopetrolito: new Coopetrolito('5000', '2001')), $this->tarifa, 'Hijo Ajeno');
    }

    public function test_asociado_inactivo_se_liquida_como_invitado(): void
    {
        $persona = (new ClasificadorAcompanantes)->clasificar(
            self::contexto(registro: new Asociado('5000', 'Ana Pérez', 'BOGOTA', 'INACTIVO')), $this->tarifa, 'Ana Pérez');

        $this->assertSame('INVITADO', $persona->tipo);
        $this->assertSame(145000, $persona->valor);
        $this->assertSame('Asociado inactivo: se liquida como invitado', $persona->observacion);
    }

    public function test_ocupan_cupo_titular_y_coopetrolito_pero_no_el_invitado(): void
    {
        $clasificador = new ClasificadorAcompanantes;

        $this->assertTrue($clasificador->ocupaCupo(PersonaInscrita::TITULAR));
        $this->assertTrue($clasificador->ocupaCupo('COOPETROLITO'));
        $this->assertFalse($clasificador->ocupaCupo('INVITADO'));
    }

    public function test_cupos_requeridos_no_cuenta_invitados(): void
    {
        $personas = [
            new PersonaInscrita('1001', 'Ana María Torres', PersonaInscrita::TITULAR, 43500),
            new PersonaInscrita('90777', 'Invitado Uno', 'INVITADO', 145000),
            new PersonaInscrita('1002', 'Carlos Pérez Rojas', 'ASOCIADO', 43500),
        ];

        $this->assertSame(2, (new ClasificadorAcompanantes)->cuposRequeridos($personas));
    }
}
