<?php

namespace App\Infraestructura\Seguridad;

use App\Aplicacion\Seguridad\AutenticadorPanel;
use App\Models\Administrador;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Session\Session;

/** Sesión del panel con el guard "web" de Laravel. Al ingresar y al salir se renueva el identificador de sesión. */
final class AutenticadorPanelLaravel implements AutenticadorPanel
{
    public function __construct(
        private readonly StatefulGuard $guard,
        private readonly Session $sesion,
    ) {}

    public function intentar(string $usuario, string $clave): ?array
    {
        if ($usuario === '' || ! $this->guard->attempt(['usuario' => $usuario, 'password' => $clave])) {
            return null;
        }
        $this->sesion->regenerate();
        /** @var Administrador $admin */
        $admin = $this->guard->user();

        return ['usuario' => $admin->usuario, 'nombre' => $admin->nombre, 'rol' => $admin->rol->value];
    }

    public function cerrar(): void
    {
        $this->guard->logout();
        $this->sesion->invalidate();
        $this->sesion->regenerateToken();
    }
}
