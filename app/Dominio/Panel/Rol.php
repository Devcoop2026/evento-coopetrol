<?php

namespace App\Dominio\Panel;

/**
 * Roles del panel.
 *   ADMINISTRADOR  todo el panel
 *   REVISOR        consultar inscripciones y soportes, aprobar o rechazar pagos y exportar
 */
enum Rol: string
{
    case Administrador = 'ADMINISTRADOR';
    case Revisor = 'REVISOR';

    public function etiqueta(): string
    {
        return $this === self::Administrador ? 'Administrador' : 'Revisor';
    }
}
