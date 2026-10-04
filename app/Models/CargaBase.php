<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Registro de cada carga masiva de bases. */
class CargaBase extends Model
{
    protected $table = 'cargas_bases';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['registros' => 'integer'];
    }
}
