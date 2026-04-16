<?php

namespace App\Repositories;

use App\Models\Seat;
use App\Repositories\Interfaces\SeatRepositoryInterface;

class SeatRepository implements SeatRepositoryInterface
{
    public function getAvailableSeats($venueId, $category = null)
    {
        $query = Seat::where('venue_id', $venueId)->where('status', 'available');
        
        if ($category) {
            $query->where('category', $category);
        }

        return $query->get();
    }

    public function updateStatus($seatId, $status)
    {
        $seat = Seat::findOrFail($seatId);
        $seat->update(['status' => $status]);
        return $seat;
    }

    public function findById($seatId)
    {
        return Seat::findOrFail($seatId);
    }
    
    public function getByVenue($venueId)
    {
        return Seat::where('venue_id', $venueId)->get();
    }
}
