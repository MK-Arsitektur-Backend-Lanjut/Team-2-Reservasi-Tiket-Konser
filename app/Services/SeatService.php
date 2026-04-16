<?php

namespace App\Services;

use App\Repositories\Interfaces\SeatRepositoryInterface;

class SeatService
{
    protected $seatRepository;

    public function __construct(SeatRepositoryInterface $seatRepository)
    {
        $this->seatRepository = $seatRepository;
    }

    public function getSeatsByVenue($venueId)
    {
        return $this->seatRepository->getByVenue($venueId);
    }

    public function getAvailableSeats($venueId, $category = null)
    {
        return $this->seatRepository->getAvailableSeats($venueId, $category);
    }
    
    public function updateSeatStatus($seatId, $status)
    {
        // Validasi status
        if (!in_array($status, ['available', 'hold', 'sold'])) {
            throw new \InvalidArgumentException('Invalid seat status');
        }
        
        return $this->seatRepository->updateStatus($seatId, $status);
    }
}
