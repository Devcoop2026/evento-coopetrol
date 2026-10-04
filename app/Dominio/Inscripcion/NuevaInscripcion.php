<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Padron\Asociado;
use DateTimeImmutable;

/** Datos para registrar una inscripción (con la autorización de habeas data: versión, IP y uso de imagen). */
final readonly class NuevaInscripcion
{
    /** @param  list<PersonaInscrita>  $personas  titular primero */
    public function __construct(
        public Asociado $titular,
        public string $agencia,
        public int $total,
        public array $personas,
        public string $versionAutorizacion,
        public ?string $ip,
        public bool $autorizaImagen,
        public DateTimeImmutable $marca,
    ) {}
}
