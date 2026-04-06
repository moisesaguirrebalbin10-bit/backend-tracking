<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BsaleDocument extends Model
{
    protected $fillable = [
        'external_id',
        'document_number',
        'serial_number',
        'generation_date',
        'emission_date',
        'total_amount',
        'state',
        'commercial_state',
        'cancellation_status',
        'client_code',
        'client_name',
        'client_email',
        'client_phone',
        'office_id',
        'office_name',
        'user_id_external',
        'user_name',
        'document_type_id',
        'document_type_name',
        'fingerprint',
        'synced_at',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'generation_date' => 'datetime',
            'emission_date' => 'datetime',
            'synced_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'payload' => 'array',
            'total_amount' => 'decimal:2',
        ];
    }
}