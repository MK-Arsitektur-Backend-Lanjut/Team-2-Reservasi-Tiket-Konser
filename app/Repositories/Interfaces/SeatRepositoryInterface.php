<?php

namespace App\Repositories\Interfaces;

interface SeatRepositoryInterface
{
    public function getAvailableSeats($venueId, $category = null);
    public function updateStatus($seatId, $status);
    public function findById($seatId);
    public function getByVenue($venueId);
}
