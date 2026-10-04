<?php

namespace App\Dominio\Compartido;

use RuntimeException;

/**
 * Regla de negocio incumplida. El manejador de excepciones la traduce a 422 con el mensaje y `datos`
 * (p. ej. la referencia de una inscripción existente).
 *
 * `identidad` marca los errores de documento/fecha de expedición: cuentan como intento fallido de la IP.
 */
class ErrorValidacion extends RuntimeException
{
    public function __construct(string $mensaje, public readonly array $datos = [], public readonly bool $identidad = false)
    {
        parent::__construct($mensaje);
    }

    public static function identidad(string $mensaje): self
    {
        return new self($mensaje, [], true);
    }
}
