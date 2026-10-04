<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Simulacion\IdentificarAsociado;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;
use App\Dominio\Inscripcion\Inscripcion;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * El asociado accede a su inscripción con documento + fecha de expedición. La referencia es opcional: si llega,
 * debe coincidir; si no, se toma la inscripción vigente del titular (o la más reciente).
 */
final class AutenticarInscripcion
{
    public function __construct(
        private readonly IdentificarAsociado $identificar,
        private readonly RepositorioInscripciones $repositorio,
    ) {}

    /** @param  array{referencia?: mixed, documento?: mixed, fechaExpedicion?: mixed}  $credenciales */
    public function ejecutar(array $credenciales): Inscripcion
    {
        $this->identificar->ejecutar($credenciales['documento'] ?? null, $credenciales['fechaExpedicion'] ?? null);
        $documento = Valores::normalizarDocumento($credenciales['documento'] ?? null);
        $referencia = mb_strtoupper(trim(Valores::cadena($credenciales['referencia'] ?? null)));
        $inscripcion = $referencia !== '' ? $this->repositorio->porReferencia($referencia) : $this->repositorio->deTitular($documento);
        if (! $inscripcion || $inscripcion->documentoTitular !== $documento) {
            throw new ErrorValidacion($referencia !== ''
                ? 'No encontramos una inscripción con esa referencia para su documento.'
                : 'No encontramos una inscripción para su documento. Inscríbase en uno de los módulos de inscripción y pago.');
        }

        return $inscripcion;
    }
}
