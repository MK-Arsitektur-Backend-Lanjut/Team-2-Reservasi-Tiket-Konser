<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    protected $fillable = ['name', 'location'];

    public function seats()
    {
        return $this->hasMany(Seat::class);
    }
}
