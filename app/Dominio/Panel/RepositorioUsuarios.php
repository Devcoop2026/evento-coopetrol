<?php

namespace App\Dominio\Panel;

/** Puerto de los usuarios del panel. */
interface RepositorioUsuarios
{
    /** @return array{usuario: string, nombre: string, rol: Rol}|null */
    public function buscar(string $usuario): ?array;

    /** Crea el usuario o cambia su clave. `rol` null conserva el actual (ADMINISTRADOR si es nuevo). */
    public function guardar(string $usuario, string $nombre, string $hashClave, ?Rol $rol): void;

    public function cambiarNombre(string $usuario, string $nombre): void;

    public function cambiarRol(string $usuario, Rol $rol): void;

    public function eliminar(string $usuario): void;

    public function contarAdministradores(): int;

    /** Cierra las sesiones abiertas del usuario (p. ej. al cambiar su clave o su rol). */
    public function cerrarSesiones(string $usuario): void;

    /** @return list<array{usuario: string, nombre: string, rol: string, actualizado_en: ?string}> */
    public function listar(): array;
}
