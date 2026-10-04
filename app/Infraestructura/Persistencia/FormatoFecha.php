<?php

namespace App\Infraestructura\Persistencia;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Conversión de marcas de tiempo entre la base (timestamptz, en UTC) y el dominio (ISO 8601: 2026-10-05T15:00:00.000Z). */
final class FormatoFecha
{
    public static function aBaseDatos(DateTimeInterface $momento): string
    {
        return DateTimeImmutable::createFromInterface($momento)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.vP');
    }

    public static function aIso(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $momento = $valor instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($valor)
            : new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));

        return $momento->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Columnas date: siempre AAAA-MM-DD. */
    public static function fecha(mixed $valor): ?string
    {
        return $valor === null || $valor === '' ? null : substr((string) $valor, 0, 10);
    }
}
