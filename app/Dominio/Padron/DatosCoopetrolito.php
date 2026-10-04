<?php

namespace App\Dominio\Padron;

final readonly class DatosCoopetrolito
{
    public function __construct(
        public string $nombre,
        public string $documentoAsociado,
        public ?string $fechaNacimiento,
    ) {}
}
