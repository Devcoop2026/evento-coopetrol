<?php

namespace App\Dominio\Inscripcion\Acompanantes;

use App\Dominio\Padron\Asociado;
use App\Dominio\Padron\Coopetrolito;
use Closure;

/** Datos con los que cada tipo de acompañante decide si aplica y valida sus condiciones. */
final class ContextoAcompanante
{
    private bool $coopetrolitoConsultado = false;

    private ?Coopetrolito $coopetrolito = null;

    /** @param  Closure(): ?Coopetrolito  $buscarCoopetrolito  consulta perezosa en la base de Coopetrolitos */
    public function __construct(
        public readonly string $documento,
        public readonly string $etiqueta,     // "Acompañante 2"
        public readonly ?string $solicitado,  // tipo elegido en el formulario (p. ej. 'COOPETROLITO')
        public readonly string $titular,      // documento del asociado titular
        public readonly ?Asociado $registro,  // asociado con ese documento en la base
        private readonly Closure $buscarCoopetrolito,
    ) {}

    public function coopetrolito(): ?Coopetrolito
    {
        if (! $this->coopetrolitoConsultado) {
            $this->coopetrolito = ($this->buscarCoopetrolito)();
            $this->coopetrolitoConsultado = true;
        }

        return $this->coopetrolito;
    }
}
