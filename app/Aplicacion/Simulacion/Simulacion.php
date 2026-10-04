<?php

namespace App\Aplicacion\Simulacion;

use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Padron\Asociado;
use App\Dominio\Padron\Tarifa;

/** Resultado de la liquidación: titular, acompañantes clasificados y total a pagar. */
final readonly class Simulacion
{
    /** @param  list<PersonaInscrita>  $acompanantes */
    public function __construct(
        public Asociado $asociado,
        public Tarifa $tarifa,
        public array $acompanantes,
    ) {}

    public function agenciaEvento(): string
    {
        return $this->tarifa->agencia;
    }

    public function titular(): PersonaInscrita
    {
        return new PersonaInscrita($this->asociado->documento, $this->asociado->nombre, PersonaInscrita::TITULAR, $this->tarifa->valorAsociado);
    }

    /** @return list<PersonaInscrita> titular primero */
    public function personas(): array
    {
        return [$this->titular(), ...$this->acompanantes];
    }

    public function total(): int
    {
        return array_sum(array_map(fn (PersonaInscrita $p) => $p->valor, $this->personas()));
    }

    private function contar(string $tipo): int
    {
        return count(array_filter($this->acompanantes, fn (PersonaInscrita $p) => $p->tipo === $tipo));
    }

    /** Estructura para la interfaz (misma forma que mostraba la versión anterior). */
    public function aArreglo(): array
    {
        return [
            'asociado' => $this->asociado->publico(),
            'agenciaEvento' => $this->agenciaEvento(),
            'tarifa' => $this->tarifa->aArreglo(),
            'modalidad' => $this->acompanantes ? 'ACOMPAÑADO' : 'SOLO',
            'titular' => ['valor' => $this->tarifa->valorAsociado],
            'acompanantes' => array_map(fn (PersonaInscrita $p) => [
                ...$p->aArreglo(), 'observacion' => $p->observacion,
            ], $this->acompanantes),
            'resumen' => [
                'personas' => 1 + count($this->acompanantes),
                'acompanantesAsociados' => $this->contar('ASOCIADO'),
                'coopetrolitos' => $this->contar('COOPETROLITO'),
                'invitados' => $this->contar('INVITADO'),
                'total' => $this->total(),
            ],
        ];
    }
}
