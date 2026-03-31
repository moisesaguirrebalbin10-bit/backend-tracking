<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Egreso extends Model
{
    protected $fillable = [
        'nombre',
        'descripcion',
        'banco_metodo_pago',
        'categoria',
        'precio',
        'fecha',
        'usuario_id',
    ];

    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function logs()
    {
        return $this->hasMany(EgresoLog::class, 'egreso_id');
    }
}
