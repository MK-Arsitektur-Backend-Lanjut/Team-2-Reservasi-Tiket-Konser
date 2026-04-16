<?php

namespace App\Repositories;

use App\Models\Venue;
use App\Repositories\Interfaces\VenueRepositoryInterface;

class VenueRepository implements VenueRepositoryInterface
{
    public function getAll()
    {
        return Venue::all();
    }

    public function findById($id)
    {
        return Venue::findOrFail($id);
    }

    public function create(array $data)
    {
        return Venue::create($data);
    }
}
