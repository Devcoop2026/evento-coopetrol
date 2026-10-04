<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Coopetrolito (hijo de un asociado). */
class Coopetrolito extends Model
{
    protected $table = 'coopetrolitos';

    protected $primaryKey = 'documento';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];
}
