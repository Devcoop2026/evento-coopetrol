<?php

namespace Tests\Unit\Dominio;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Inscripcion\AccionRevision;
use App\Dominio\Inscripcion\EstadoInscripcion;
use App\Dominio\Inscripcion\PoliticaCupos;
use App\Dominio\Inscripcion\Referencia;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Acciones de revisión, política de cupos y referencia de la inscripción. */
class ReglasInscripcionTest extends TestCase
{
    public function test_aprobar_desde_en_revision_confirma_sin_motivo(): void
    {
        $cambio = AccionRevision::desde('APROBAR')->aplicar(EstadoInscripcion::EnRevision, null);

        $this->assertSame(EstadoInscripcion::Confirmado, $cambio->hacia);
        $this->assertNull($cambio->motivo);
    }

    public function test_no_se_puede_aprobar_una_preinscripcion(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/No se puede aprobar/');

        AccionRevision::Aprobar->aplicar(EstadoInscripcion::Preinscrito, null);
    }

    public function test_rechazar_exige_motivo(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/motivo/');

        AccionRevision::Rechazar->aplicar(EstadoInscripcion::EnRevision, '  ');
    }

    public function test_accion_desconocida_se_rechaza(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/Acción no válida/');

        AccionRevision::desde('BORRAR');
    }

    public function test_politica_de_cupos_informa_cuantos_quedan(): void
    {
        $this->expectException(ErrorValidacion::class);
        $this->expectExceptionMessageMatches('/quedan 2 y su inscripción requiere 3/');

        PoliticaCupos::exigir('BOGOTA', 3, 2);
    }

    public function test_politica_de_cupos_sin_cupos_disponibles(): void
    {
        try {
            PoliticaCupos::exigir('BOGOTA', 1, 0);
            $this->fail('Debió rechazar la inscripción.');
        } catch (ErrorValidacion $error) {
            $this->assertSame('Lo sentimos, se ha superado el límite de cupos disponibles para el evento de BOGOTA.', $error->getMessage());
        }
    }

    public function test_politica_de_cupos_acepta_si_alcanzan_justo(): void
    {
        PoliticaCupos::exigir('BOGOTA', 2, 2);

        $this->addToAssertionCount(1);
    }

    public function test_referencia_con_anio_y_consecutivo(): void
    {
        $this->assertSame('EVT26-000042', Referencia::generar(42, new DateTimeImmutable('2026-10-01')));
    }
}
