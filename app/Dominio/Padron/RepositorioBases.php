<?php

namespace App\Dominio\Padron;

use DateTimeImmutable;

/** Puerto de la carga masiva: reemplaza una base completa (todo o nada) y registra quién la cargó. */
interface RepositorioBases
{
    /** @return list<string> */
    public function agenciasValidas(): array;

    /** @return list<string> documentos de la base de asociados */
    public function documentosAsociados(): array;

    /** @param  list<array{documento: string, nombre: string, agencia: string, estado: string, fecha_actualizacion: ?string, expedicion_hmac: ?string, fecha_nacimiento: ?string}>  $registros */
    public function reemplazarAsociados(array $registros): void;

    /** @param  list<array{documento: string, nombre: string, documento_asociado: string, fecha_nacimiento: ?string}>  $registros */
    public function reemplazarCoopetrolitos(array $registros): void;

    public function registrarCarga(string $tipo, ?string $archivo, int $registros, string $usuario, DateTimeImmutable $marca): void;

    /** @return array{total: int, ultimaCarga: ?array{archivo: ?string, registros: int, usuario: string, cargada_en: string}} */
    public function estado(string $tipo): array;
}
