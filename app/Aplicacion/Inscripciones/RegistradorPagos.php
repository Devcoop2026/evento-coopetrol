<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\EstadoInscripcion;
use App\Dominio\Inscripcion\NuevoSoporte;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use DateTimeImmutable;
use Throwable;

/** Guarda el comprobante y registra el pago, dejando la inscripción EN_REVISION (ocupa el cupo). */
final class RegistradorPagos
{
    public function __construct(
        private readonly RepositorioInscripciones $repositorio,
        private readonly AlmacenComprobantes $almacen,
        private readonly UnidadDeTrabajo $unidad,
        private readonly ConfiguracionEvento $configuracion,
    ) {}

    /**
     * Guarda el comprobante (si hay) y ejecuta `registrar(nombreArchivo)` en la unidad de trabajo; si falla, borra el
     * archivo para no dejar comprobantes huérfanos.
     *
     * @template T
     *
     * @param  callable(string): T  $registrar
     * @return T
     */
    public function conComprobante(PagoValidado $pago, callable $registrar): mixed
    {
        $nombre = $pago->comprobante !== null ? $this->almacen->guardar($pago->comprobante, $pago->tipoComprobante) : '';
        try {
            return $this->unidad->ejecutar(fn () => $registrar($nombre));
        } catch (Throwable $error) {
            if ($nombre !== '') {
                $this->almacen->borrar($nombre);
            }
            throw $error;
        }
    }

    /** Registra el soporte y deja la inscripción EN_REVISION. Debe llamarse dentro de la unidad de trabajo. */
    public function registrar(int $inscripcionId, int $total, PagoValidado $pago, string $archivo, DateTimeImmutable $marca): void
    {
        $this->repositorio->insertarSoporte(new NuevoSoporte(
            inscripcionId: $inscripcionId,
            medioPago: $pago->medio,
            datosMedio: $pago->datosMedio,
            fechaPago: $pago->fecha,
            valorPagado: $pago->valor,
            campos: $pago->adicionales,
            archivo: $archivo,
            tipoArchivo: $pago->tipoComprobante?->value ?? '',
            nombreOriginal: $pago->nombreOriginal,
            alerta: $pago->valor !== $total ? "Valor pagado {$pago->valor} distinto del total {$total}" : null,
            versionAutorizacion: $this->configuracion->versionHabeasData(),
            marca: $marca,
        ));
        $this->repositorio->cambiarEstado($inscripcionId, EstadoInscripcion::EnRevision, null, $marca);
    }
}
