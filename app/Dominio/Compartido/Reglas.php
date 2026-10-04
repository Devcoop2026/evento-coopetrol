<?php

namespace App\Dominio\Compartido;

/**
 * Reglas de validación por tipo de campo (las mismas que aplica el navegador en public/validacion.js).
 *   numérico:      solo dígitos (documentos, CUS, valor, celular)
 *   texto:         letras (con tildes y ñ), espacios, apóstrofo, punto y guion (nombres)
 *   alfanumérico:  letras y números en mayúsculas (placa, recibo de caja)
 */
final class Reglas
{
    private const LETRAS = 'A-Za-zÁÉÍÓÚÜÑáéíóúüñ';

    public const REGLAS = [
        'numerico' => ['patron' => '/^\d+$/D', 'mensaje' => 'solo debe contener números'],
        'texto' => ['patron' => '/^['.self::LETRAS.']['.self::LETRAS."' .-]*$/Du", 'mensaje' => 'solo debe contener letras y espacios'],
        'alfanumerico' => ['patron' => '/^[A-Z0-9-]+$/D', 'mensaje' => 'solo debe contener letras y números'],
    ];

    /** Devuelve el mensaje de error o null. `etiqueta` encabeza el mensaje (p. ej. "El documento"). */
    public static function revisar(string $tipo, mixed $valor, string $etiqueta): ?string
    {
        $regla = self::REGLAS[$tipo] ?? null;

        return $regla && ! preg_match($regla['patron'], Valores::cadena($valor)) ? "{$etiqueta} {$regla['mensaje']}." : null;
    }

    public static function esNumerico(mixed $valor): bool
    {
        return (bool) preg_match(self::REGLAS['numerico']['patron'], Valores::cadena($valor));
    }

    public static function esTexto(mixed $valor): bool
    {
        return (bool) preg_match(self::REGLAS['texto']['patron'], Valores::cadena($valor));
    }
}
