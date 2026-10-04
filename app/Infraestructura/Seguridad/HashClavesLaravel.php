<?php

namespace App\Infraestructura\Seguridad;

use App\Dominio\Panel\HashClaves;
use Illuminate\Contracts\Hashing\Hasher;

final class HashClavesLaravel implements HashClaves
{
    public function __construct(private readonly Hasher $hasher) {}

    public function hash(string $clave): string
    {
        return $this->hasher->make($clave);
    }
}
