<?php

namespace App\Infraestructura\Respaldo;

use RuntimeException;

/**
 * Cifrado de archivos de respaldo con AES-256-GCM (confidencialidad + integridad). Mismo formato que la versión
 * anterior, así sus respaldos se pueden descifrar con esta:
 *   "EVTENC1\n" (8 bytes) | IV (12 bytes) | etiqueta de autenticación (16 bytes) | datos cifrados.
 */
final class CifradoRespaldo
{
    private const MAGIA = "EVTENC1\n";

    public static function clave(?string $hex): ?string
    {
        if ($hex === null || $hex === '') {
            return null;
        }
        if (! preg_match('/^[0-9a-f]{64}$/Di', $hex)) {
            throw new RuntimeException('RESPALDO_CLAVE debe tener 64 caracteres hexadecimales (32 bytes).');
        }

        return hex2bin($hex);
    }

    public static function cifrar(string $origen, string $destino, string $clave): void
    {
        $iv = random_bytes(12);
        $cifrado = openssl_encrypt((string) file_get_contents($origen), 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, $iv, $etiqueta, '', 16);
        if ($cifrado === false) {
            throw new RuntimeException("No se pudo cifrar {$origen}.");
        }
        file_put_contents($destino, self::MAGIA.$iv.$etiqueta.$cifrado);
        @chmod($destino, 0600);
    }

    public static function descifrar(string $origen, string $destino, string $clave): void
    {
        $contenido = (string) file_get_contents($origen);
        if (! str_starts_with($contenido, self::MAGIA)) {
            throw new RuntimeException("{$origen} no es un respaldo cifrado válido.");
        }
        $claro = openssl_decrypt(substr($contenido, 36), 'aes-256-gcm', $clave, OPENSSL_RAW_DATA, substr($contenido, 8, 12), substr($contenido, 20, 16));
        if ($claro === false) {
            throw new RuntimeException("No se pudo descifrar {$origen}: la clave no corresponde o el archivo está alterado.");
        }
        file_put_contents($destino, $claro);
        @chmod($destino, 0600);
    }
}
