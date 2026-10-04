<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Asociado de la base cargada (la fecha de expedición solo como HMAC). */
class Asociado extends Model
{
    protected $table = 'asociados';

    protected $primaryKey = 'documento';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
