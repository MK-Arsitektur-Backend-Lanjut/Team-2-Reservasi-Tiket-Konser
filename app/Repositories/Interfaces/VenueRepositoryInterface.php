<?php

namespace App\Repositories\Interfaces;

interface VenueRepositoryInterface
{
    public function getAll();
    public function findById($id);
    public function create(array $data);
}
