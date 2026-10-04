<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Agencia o punto de atención y el evento al que asiste. */
class AgenciaEvento extends Model
{
    protected $table = 'agencias_evento';

    protected $primaryKey = 'agencia';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
