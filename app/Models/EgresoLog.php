<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EgresoLog extends Model
{
    protected $fillable = [
        'egreso_id',
        'usuario_id',
        'accion',
        'datos_anteriores',
        'datos_nuevos',
        'fecha',
    ];

    protected $casts = [
        'datos_anteriores' => 'array',
        'datos_nuevos' => 'array',
        'fecha' => 'datetime',
    ];

    public function egreso()
    {
        return $this->belongsTo(Egreso::class, 'egreso_id');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
