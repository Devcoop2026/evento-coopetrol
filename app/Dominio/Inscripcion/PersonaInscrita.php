<?php

namespace App\Dominio\Inscripcion;

/** Persona incluida en una inscripción (titular o acompañante) con el valor que paga. */
final readonly class PersonaInscrita
{
    public const TITULAR = 'TITULAR';

    public function __construct(
        public string $documento,
        public string $nombre,
        public string $tipo,
        public int $valor,
        public ?string $observacion = null,
    ) {}

    public function aArreglo(): array
    {
        return ['documento' => $this->documento, 'nombre' => $this->nombre, 'tipo' => $this->tipo, 'valor' => $this->valor];
    }
}
