<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cambio manual hecho desde el panel. */
class RegistroAuditoria extends Model
{
    protected $table = 'auditoria';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];
}
