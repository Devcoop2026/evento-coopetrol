<?php

namespace App\Aplicacion\Panel;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Panel\Rol;

/** Avisos para los administradores (p. ej. ya se cumplió la fecha de supresión de datos personales). */
final class AvisosPanel
{
    public function __construct(
        private readonly ConfiguracionEvento $configuracion,
        private readonly Reloj $reloj,
    ) {}

    /** @return list<string> */
    public function ejecutar(Rol $rol): array
    {
        $fecha = $this->configuracion->fechaSupresionDatos();
        if ($rol !== Rol::Administrador || ! $fecha || Valores::fechaColombia($this->reloj->ahora()) < $fecha) {
            return [];
        }

        return ['Se cumplió la fecha de supresión de datos personales ('.Valores::formatoFecha($fecha).'). '
            .'Exporte lo necesario y ejecute la limpieza de datos (php artisan evento:limpiar todo).'];
    }
}
