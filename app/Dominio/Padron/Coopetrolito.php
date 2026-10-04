<?php

namespace App\Dominio\Padron;

/** Hijo de un asociado (programa Coopetrolitos): paga tarifa de asociado y ocupa cupo. */
final readonly class Coopetrolito
{
    public function __construct(
        public string $documento,
        public string $documentoAsociado,
    ) {}

    public function esHijoDe(string $documentoTitular): bool
    {
        return $this->documentoAsociado === $documentoTitular;
    }
}
