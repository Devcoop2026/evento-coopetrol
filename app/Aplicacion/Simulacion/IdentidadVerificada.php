<?php

namespace App\Aplicacion\Simulacion;

use App\Dominio\Padron\Asociado;
use App\Dominio\Padron\Tarifa;

/** Asociado identificado (documento + fecha de expedición) y la tarifa del evento al que asiste su agencia. */
final readonly class IdentidadVerificada
{
    public function __construct(
        public Asociado $asociado,
        public Tarifa $tarifa,
    ) {}
}
