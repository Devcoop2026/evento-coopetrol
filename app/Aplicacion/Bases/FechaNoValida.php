<?php

namespace App\Aplicacion\Bases;

use RuntimeException;

/** Fecha de una fila que no se pudo interpretar (la fila se omite con este motivo). */
final class FechaNoValida extends RuntimeException {}
