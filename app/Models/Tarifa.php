<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tarifas y cupos por evento (agencia). */
class Tarifa extends Model
{
    protected $table = 'tarifas';

    protected $primaryKey = 'agencia';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cupos' => 'integer', 'valor_invitado' => 'integer', 'valor_asociado' => 'integer'];
    }
}
