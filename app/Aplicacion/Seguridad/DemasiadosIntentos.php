<?php

namespace App\Aplicacion\Seguridad;

use RuntimeException;

/** Se superó un límite de intentos; `segundos` indica cuánto falta para liberarse. */
final class DemasiadosIntentos extends RuntimeException
{
    public function __construct(string $mensaje, public readonly int $segundos)
    {
        parent::__construct($mensaje);
    }
}
