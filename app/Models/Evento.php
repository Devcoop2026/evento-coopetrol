<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Datos generales del evento (una sola fila, id = 1). */
class Evento extends Model
{
    protected $table = 'evento';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];
}
