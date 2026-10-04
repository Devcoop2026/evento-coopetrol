<?php

namespace App\Console\Commands;

use App\Aplicacion\Gestion\GestionUsuarios;
use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Panel\RepositorioUsuarios;
use App\Dominio\Panel\Rol;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

/**
 * Crea un usuario del panel o cambia su clave. La clave se toma de ADMIN_CLAVE (despliegues sin consola interactiva)
 * o se pide dos veces sin mostrarla.
 */
final class CrearUsuario extends Command
{
    protected $signature = 'evento:usuario
        {usuario : Usuario del panel (3 a 40 letras, números, . _ -)}
        {nombre? : Nombre completo}
        {--rol= : ADMINISTRADOR o REVISOR (por defecto ADMINISTRADOR al crear; al actualizar conserva el actual)}';

    protected $description = 'Crea o actualiza un usuario del panel de administración';

    public function handle(GestionUsuarios $usuarios, RepositorioUsuarios $repositorio): int
    {
        $rol = null;
        if (($valorRol = $this->option('rol')) !== null && $valorRol !== '') {
            $rol = Rol::tryFrom(mb_strtoupper(trim($valorRol)));
            if (! $rol) {
                $this->error('Rol inválido. Use ADMINISTRADOR o REVISOR.');

                return self::FAILURE;
            }
        }

        $clave = Env::get('ADMIN_CLAVE');
        if ($clave === null || $clave === '') {
            $clave = (string) $this->secret('Clave (mínimo 10 caracteres)');
            if ($clave !== (string) $this->secret('Repita la clave')) {
                $this->error('Las claves no coinciden.');

                return self::FAILURE;
            }
        }

        try {
            $id = $usuarios->guardar($this->argument('usuario'), $this->argument('nombre'), $clave, $rol);
        } catch (ErrorValidacion $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $rolFinal = $repositorio->buscar($id)['rol'] ?? Rol::Administrador;
        $this->info("Usuario \"{$id}\" listo (rol {$rolFinal->value}). Ingrese en /admin");

        return self::SUCCESS;
    }
}
