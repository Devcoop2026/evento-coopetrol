<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pago registrado para una inscripción. */
class Soporte extends Model
{
    protected $table = 'soportes';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['valor_pagado' => 'integer', 'campos' => 'array'];
    }
}
