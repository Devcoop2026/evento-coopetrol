<?php

namespace App\Dominio\Padron;

/**
 * Puerto de lectura de las bases cargadas: asociados, Coopetrolitos, el evento y sus tarifas, y qué agencias asisten
 * a cada evento (en un evento compartido, varias agencias asisten al mismo).
 */
interface RepositorioPadron
{
    public function asociado(string $documento): ?Asociado;

    public function coopetrolito(string $documento): ?Coopetrolito;

    public function tarifa(string $agencia): ?Tarifa;

    /** @return list<Tarifa> ordenadas por agencia */
    public function tarifas(): array;

    /** Evento al que asiste una agencia (en un evento compartido, el nombre del evento); null si no existe. */
    public function eventoDeAgencia(string $agencia): ?string;

    /** Agencia o punto de atención válido (donde se paga), o nombre de un evento. */
    public function existeAgencia(string $agencia): bool;

    /** @return list<array{agencia: string, evento: string}> agencias y puntos de atención con su evento */
    public function agencias(): array;

    /** @return array{nombre: string, inscripciones: ?string, cuenta_contable: ?string, concepto: ?string}|null */
    public function evento(): ?array;
}
