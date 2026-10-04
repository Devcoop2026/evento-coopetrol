<?php

namespace App\Aplicacion\Panel;

use RuntimeException;

/** El rol del usuario no permite la acción (p. ej. un revisor que intenta anular). */
class PermisoDenegado extends RuntimeException
{
    public function __construct(public readonly string $accion)
    {
        parent::__construct('Su rol no tiene permiso para esta acción.');
    }
}
