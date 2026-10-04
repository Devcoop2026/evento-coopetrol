<?php

namespace App\Dominio\Compartido;

use DateTimeInterface;
use DateTimeZone;

/**
 * Valores del dominio: normalización y validación de documento, nombre y fechas, compartidas por los casos de uso.
 * `etiqueta` antepone el sujeto al mensaje (p. ej. "Acompañante 2" -> "Acompañante 2: ingrese el número de documento.").
 */
final class Valores
{
    /** Texto de un valor recibido en JSON (equivale a String(valor ?? '') en el navegador). */
    public static function cadena(mixed $valor): string
    {
        return match (true) {
            $valor === null => '',
            is_bool($valor) => $valor ? 'true' : 'false',
            is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15 => sprintf('%.0f', $valor),
            is_scalar($valor) => (string) $valor,
            default => '',
        };
    }

    private static function error(?string $etiqueta, string $texto): ErrorValidacion
    {
        return new ErrorValidacion($etiqueta ? "{$etiqueta}: {$texto}" : mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1));
    }

    /** Documento: se ignoran espacios, puntos, comas y guiones ("1.234.567-8" -> "12345678"). */
    public static function normalizarDocumento(mixed $valor): string
    {
        return mb_strtoupper(preg_replace('/[\s.,-]/u', '', self::cadena($valor)));
    }

    /** Devuelve el documento normalizado (solo dígitos, entre `minimo` y `maximo`) o lanza el error. */
    public static function validarDocumento(mixed $valor, ?string $etiqueta = null, int $minimo = 4, int $maximo = 15): string
    {
        $doc = self::normalizarDocumento($valor);
        if ($doc === '') {
            throw self::error($etiqueta, 'ingrese el número de documento.');
        }
        if (! Reglas::esNumerico($doc)) {
            throw self::error($etiqueta, 'el número de documento solo debe contener números.');
        }
        if (strlen($doc) < $minimo || strlen($doc) > $maximo) {
            throw self::error($etiqueta, "el número de documento debe tener entre {$minimo} y {$maximo} números.");
        }

        return $doc;
    }

    /** Nombres y apellidos completos: al menos dos palabras de dos letras, solo letras y espacios, máximo 100 caracteres. */
    public static function validarNombreCompleto(mixed $valor, ?string $etiqueta = null): string
    {
        $nombre = preg_replace('/\s+/u', ' ', trim(self::cadena($valor)));
        if ($nombre !== '' && ! Reglas::esTexto($nombre)) {
            throw self::error($etiqueta, 'el nombre solo debe contener letras y espacios.');
        }
        $palabras = array_filter(explode(' ', $nombre), fn ($p) => mb_strlen($p) >= 2);
        if (count($palabras) < 2) {
            throw self::error($etiqueta, 'ingrese nombres y apellidos completos.');
        }
        if (mb_strlen($nombre) > 100) {
            throw self::error($etiqueta, 'el nombre es demasiado largo.');
        }

        return $nombre;
    }

    /** Fechas AAAA-MM-DD reales (rechaza 2026-02-30). */
    public static function esFechaValida(mixed $valor): bool
    {
        return is_string($valor) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Devuelve la fecha AAAA-MM-DD, null si es opcional y viene vacía, o lanza el error. `nombre`: "la fecha de expedición". */
    public static function validarFecha(mixed $valor, string $nombre, bool $opcional = false): ?string
    {
        $fecha = trim(self::cadena($valor));
        if ($fecha === '') {
            if ($opcional) {
                return null;
            }
            throw self::error(null, "ingrese {$nombre}.");
        }
        if (! self::esFechaValida($fecha)) {
            throw self::error(null, "{$nombre} no es válida.");
        }

        return $fecha;
    }

    /** Fecha calendario en Colombia (AAAA-MM-DD), independiente de la zona horaria del servidor. */
    public static function fechaColombia(DateTimeInterface $momento): string
    {
        return (new \DateTimeImmutable('@'.$momento->getTimestamp()))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d');
    }

    /** AAAA-MM-DD -> DD/MM/AAAA (para mensajes). */
    public static function formatoFecha(string $iso): string
    {
        return implode('/', array_reverse(explode('-', $iso)));
    }
}
