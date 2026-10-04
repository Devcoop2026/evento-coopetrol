<?php

namespace Tests;

use App\Dominio\Seguridad\RegistroSeguridad;

/** Registro de seguridad que guarda los eventos en memoria (para las pruebas). */
final class RegistroSeguridadFalso implements RegistroSeguridad
{
    /** @var list<array{evento: string, datos: array}> */
    public array $eventos = [];

    public function registrar(string $evento, array $datos = []): void
    {
        $this->eventos[] = ['evento' => $evento, 'datos' => $datos];
    }

    /** @return list<array> datos de los eventos con ese nombre */
    public function de(string $evento): array
    {
        return array_values(array_map(fn ($e) => $e['datos'], array_filter($this->eventos, fn ($e) => $e['evento'] === $evento)));
    }

    /** Todo lo registrado como texto (para comprobar que no se filtran claves ni fechas). */
    public function comoTexto(): string
    {
        return json_encode($this->eventos, JSON_UNESCAPED_UNICODE);
    }
}
