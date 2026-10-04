<?php

namespace App\Infraestructura\Seguridad;

use App\Dominio\Seguridad\HuellaFecha;
use RuntimeException;

/**
 * HMAC-SHA256 de la fecha de expedición con EVENTO_SECRETO (compatible con las bases cargadas por la versión anterior).
 * Cambiar la clave invalida las huellas guardadas: habría que volver a cargar la base de asociados.
 */
final class HuellaFechaHmac implements HuellaFecha
{
    public const CLAVE_DESARROLLO = 'solo-desarrollo-no-usar-en-produccion';

    private readonly string $clave;

    public function __construct(?string $clave, bool $produccion)
    {
        $this->clave = self::validarClave($clave, $produccion);
    }

    public static function validarClave(?string $clave, bool $produccion): string
    {
        if ($clave !== null && $clave !== '') {
            if (strlen($clave) < 32) {
                throw new RuntimeException('EVENTO_SECRETO debe tener al menos 32 caracteres.');
            }

            return $clave;
        }
        if ($produccion) {
            throw new RuntimeException('Falta EVENTO_SECRETO en la configuración de producción.');
        }

        return self::CLAVE_DESARROLLO;
    }

    public function calcular(string $fecha): string
    {
        return hash_hmac('sha256', "expedicion:{$fecha}", $this->clave);
    }

    public function coincide(string $fecha, ?string $huella): bool
    {
        return $huella !== null && $huella !== '' && hash_equals($huella, $this->calcular($fecha));
    }
}
