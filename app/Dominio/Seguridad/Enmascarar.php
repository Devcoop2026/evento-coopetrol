<?php

namespace App\Dominio\Seguridad;

use App\Dominio\Compartido\Valores;

final class Enmascarar
{
    /** "12345678" -> "*****678". */
    public static function documento(mixed $documento): string
    {
        $d = Valores::cadena($documento);

        return strlen($d) <= 3 ? '***' : str_repeat('*', strlen($d) - 3).substr($d, -3);
    }
}
