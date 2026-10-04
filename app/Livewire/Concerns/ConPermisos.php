<?php

namespace App\Livewire\Concerns;

use App\Aplicacion\Panel\PermisoDenegado;
use App\Dominio\Panel\Rol;
use App\Dominio\Seguridad\RegistroSeguridad;
use App\Models\Administrador;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Permisos del panel. La interfaz oculta lo que el rol no permite, pero cada acción se verifica en el servidor:
 * el REVISOR que intenta una acción de ADMINISTRADOR recibe el error y queda en el registro de seguridad.
 */
trait ConPermisos
{
    protected function usuario(): Administrador
    {
        /** @var Administrador */
        return Auth::user();
    }

    protected function esAdministrador(): bool
    {
        return Gate::allows('administrar');
    }

    protected function rol(): Rol
    {
        return $this->usuario()->rol;
    }

    protected function exigirAdministrador(string $accion): void
    {
        if ($this->esAdministrador()) {
            return;
        }
        app(RegistroSeguridad::class)->registrar('permiso_denegado', [
            'usuario' => $this->usuario()->usuario, 'rol' => $this->rol()->value, 'accion' => $accion, 'ip' => request()->ip(),
        ]);
        throw new PermisoDenegado($accion);
    }
}
