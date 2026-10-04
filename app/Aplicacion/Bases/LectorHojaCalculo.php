<?php

namespace App\Aplicacion\Bases;

/** Puerto: lee la primera hoja de un Excel (.xlsx) o CSV, detectando el formato por el contenido. */
interface LectorHojaCalculo
{
    /**
     * @return array{filas: list<list<mixed>>, fecha1904: bool} celdas numéricas como int/float (las fechas de Excel
     *                                                          son números de serie), booleanas como bool
     *
     * @throws \RuntimeException con un mensaje para el usuario si el archivo no se puede leer
     */
    public function leer(string $contenido): array;
}
