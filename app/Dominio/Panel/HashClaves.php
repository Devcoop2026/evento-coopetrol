<?php

namespace App\Dominio\Panel;

/** Puerto: hash irreversible de las claves del panel. */
interface HashClaves
{
    public function hash(string $clave): string;
}
