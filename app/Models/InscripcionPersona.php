<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Persona incluida en una inscripción. */
class InscripcionPersona extends Model
{
    protected $table = 'inscripcion_personas';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['valor' => 'integer'];
    }
}
