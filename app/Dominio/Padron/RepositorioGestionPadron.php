<?php

namespace App\Dominio\Padron;

/** Puerto de escritura y listado de asociados y Coopetrolitos (gestión manual desde el panel). */
interface RepositorioGestionPadron
{
    /** @return list<string> agencias válidas: las que asisten a un evento y los nombres de los eventos */
    public function agenciasValidas(): array;

    public function existeAsociado(string $documento): bool;

    public function crearAsociado(string $documento, DatosAsociado $datos): void;

    public function actualizarAsociado(string $documento, DatosAsociado $datos): void;

    public function eliminarAsociado(string $documento): void;

    public function coopetrolitosDe(string $documentoAsociado): int;

    /** @return array{filas: list<array>, total: int, pagina: int, paginas: int} */
    public function listarAsociados(?string $busqueda, int $pagina, int $porPagina): array;

    public function existeCoopetrolito(string $documento): bool;

    public function crearCoopetrolito(string $documento, DatosCoopetrolito $datos): void;

    public function actualizarCoopetrolito(string $documento, DatosCoopetrolito $datos): void;

    public function eliminarCoopetrolito(string $documento): void;

    /** @return array{filas: list<array>, total: int, pagina: int, paginas: int} */
    public function listarCoopetrolitos(?string $busqueda, int $pagina, int $porPagina): array;
}
