<?php

namespace App\Services;

use App\Repositories\Interfaces\VenueRepositoryInterface;

class VenueService
{
    protected $venueRepository;

    public function __construct(VenueRepositoryInterface $venueRepository)
    {
        $this->venueRepository = $venueRepository;
    }

    public function getAllVenues()
    {
        return $this->venueRepository->getAll();
    }
    
    public function getVenueDetails($id)
    {
        return $this->venueRepository->findById($id);
    }
}
