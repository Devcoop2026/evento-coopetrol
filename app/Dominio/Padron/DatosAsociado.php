<?php

namespace App\Dominio\Padron;

/** Datos editables de un asociado. `huellaExpedicion` null al editar = conservar la registrada. */
final readonly class DatosAsociado
{
    public function __construct(
        public string $nombre,
        public string $agencia,
        public string $estado,
        public ?string $fechaActualizacion,
        public ?string $fechaNacimiento,
        public ?string $huellaExpedicion,
    ) {}
}
