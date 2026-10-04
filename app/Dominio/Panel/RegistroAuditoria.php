<?php

namespace App\Dominio\Panel;

use DateTimeImmutable;

/** Puerto: registro de cambios manuales hechos desde el panel (quién, qué y cuándo). */
interface RegistroAuditoria
{
    /** `accion`: CREAR, EDITAR, ELIMINAR; `entidad`: asociado, coopetrolito, usuario. */
    public function registrar(string $usuario, string $accion, string $entidad, string $clave, ?string $detalle, DateTimeImmutable $marca): void;

    /** @return list<array{usuario: string, accion: string, entidad: string, clave: string, detalle: ?string, fecha: string}> */
    public function ultimos(int $limite): array;
}
