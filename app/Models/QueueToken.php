<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QueueToken extends Model
{
    protected $fillable = [
        'user_id',
        'venue_id',
        'token',
        'used_at',
        'expired_at',
    ];

    protected $casts = [
        'used_at'    => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function isExpired(): bool
    {
        return $this->expired_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
