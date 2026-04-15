<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seat extends Model
{
    protected $fillable = ['venue_id', 'seat_number', 'category', 'price', 'status'];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
