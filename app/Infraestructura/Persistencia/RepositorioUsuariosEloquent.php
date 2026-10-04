<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Panel\RepositorioUsuarios;
use App\Dominio\Panel\Rol;
use App\Models\Administrador;
use App\Models\RegistroAuditoria;
use Illuminate\Database\ConnectionInterface;

final class RepositorioUsuariosEloquent implements RepositorioUsuarios
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function buscar(string $usuario): ?array
    {
        $a = Administrador::query()->find($usuario);

        return $a ? ['usuario' => $a->usuario, 'nombre' => $a->nombre, 'rol' => $a->rol] : null;
    }

    public function guardar(string $usuario, string $nombre, string $hashClave, ?Rol $rol): void
    {
        $existente = Administrador::query()->find($usuario);
        if ($existente) {
            $existente->forceFill(['nombre' => $nombre, 'clave' => $hashClave, 'clave_salt' => null, 'rol' => $rol ?? $existente->rol])->save();

            return;
        }
        Administrador::query()->create([
            'usuario' => $usuario, 'nombre' => $nombre, 'clave' => $hashClave, 'clave_salt' => null, 'rol' => $rol ?? Rol::Administrador,
        ]);
    }

    public function cambiarNombre(string $usuario, string $nombre): void
    {
        Administrador::query()->whereKey($usuario)->update(['nombre' => $nombre]);
    }

    public function cambiarRol(string $usuario, Rol $rol): void
    {
        Administrador::query()->whereKey($usuario)->update(['rol' => $rol->value]);
    }

    public function eliminar(string $usuario): void
    {
        Administrador::query()->whereKey($usuario)->delete();
    }

    public function contarAdministradores(): int
    {
        return Administrador::query()->where('rol', Rol::Administrador->value)->count();
    }

    public function cerrarSesiones(string $usuario): void
    {
        $this->db->table('sessions')->where('user_id', $usuario)->delete();
    }

    public function listar(): array
    {
        return Administrador::query()->orderBy('usuario')
            ->addSelect(['actualizado_en' => RegistroAuditoria::query()->selectRaw('MAX(fecha)')
                ->where('entidad', 'usuario')->whereColumn('clave', 'administradores.usuario')])
            ->get()
            ->map(fn (Administrador $a) => [
                'usuario' => $a->usuario,
                'nombre' => $a->nombre,
                'rol' => $a->rol->value,
                'actualizado_en' => FormatoFecha::aIso($a->actualizado_en),
            ])->all();
    }
}
