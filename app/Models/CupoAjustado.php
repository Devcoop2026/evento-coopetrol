<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cupo de un evento ajustado desde el panel. */
class CupoAjustado extends Model
{
    protected $table = 'cupos_ajustados';

    protected $primaryKey = 'agencia';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cupos' => 'integer', 'actualizado_en' => 'immutable_datetime'];
    }
}
