<?php

namespace App\Dominio\Padron;

/** Valores y cupo de un evento (identificado por la agencia que lo organiza). */
final readonly class Tarifa
{
    public function __construct(
        public string $agencia,
        public int $cupos,
        public int $valorInvitado,
        public int $valorAsociado,
    ) {}

    public function aArreglo(): array
    {
        return [
            'agencia' => $this->agencia,
            'cupos' => $this->cupos,
            'valor_invitado' => $this->valorInvitado,
            'valor_asociado' => $this->valorAsociado,
        ];
    }
}
