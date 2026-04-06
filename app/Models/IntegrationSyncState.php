<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSyncState extends Model
{
    protected $fillable = [
        'integration',
        'scope',
        'status',
        'last_started_at',
        'last_finished_at',
        'last_cursor_at',
        'last_full_sync_at',
        'error_message',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
            'last_cursor_at' => 'datetime',
            'last_full_sync_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}