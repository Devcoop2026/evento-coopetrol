<?php

namespace App\Aplicacion\Seguridad;

/** Puerto: sesión del panel (lo implementa la autenticación de Laravel). */
interface AutenticadorPanel
{
    /**
     * Valida usuario y clave y abre la sesión.
     *
     * @return array{usuario: string, nombre: string, rol: string}|null null si no coinciden
     */
    public function intentar(string $usuario, string $clave): ?array;

    public function cerrar(): void;
}
