<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Inscripción al evento. */
class Inscripcion extends Model
{
    protected $table = 'inscripciones';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['total' => 'integer', 'autorizacion_imagen' => 'boolean'];
    }

    /** Personas en el orden en que se registraron (titular primero). */
    public function personas(): HasMany
    {
        return $this->hasMany(InscripcionPersona::class)->orderBy('id');
    }

    /** Pagos registrados, del más reciente al más antiguo. */
    public function soportes(): HasMany
    {
        return $this->hasMany(Soporte::class)->orderByDesc('id');
    }
}
