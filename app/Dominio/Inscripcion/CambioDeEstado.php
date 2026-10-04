<?php

namespace App\Dominio\Inscripcion;

final readonly class CambioDeEstado
{
    public function __construct(
        public EstadoInscripcion $hacia,
        public ?string $motivo,
    ) {}
}
