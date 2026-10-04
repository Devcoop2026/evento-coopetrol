<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Panel\RegistroAuditoria;
use App\Models\RegistroAuditoria as Modelo;
use DateTimeImmutable;

final class RegistroAuditoriaEloquent implements RegistroAuditoria
{
    public function registrar(string $usuario, string $accion, string $entidad, string $clave, ?string $detalle, DateTimeImmutable $marca): void
    {
        Modelo::query()->create([
            'usuario' => $usuario, 'accion' => $accion, 'entidad' => $entidad, 'clave' => $clave,
            'detalle' => $detalle, 'fecha' => FormatoFecha::aBaseDatos($marca),
        ]);
    }

    public function ultimos(int $limite): array
    {
        return Modelo::query()->orderByDesc('id')->limit($limite)->get()->map(fn (Modelo $a) => [
            'usuario' => $a->usuario,
            'accion' => $a->accion,
            'entidad' => $a->entidad,
            'clave' => $a->clave,
            'detalle' => $a->detalle,
            'fecha' => FormatoFecha::aIso($a->fecha),
        ])->all();
    }
}
