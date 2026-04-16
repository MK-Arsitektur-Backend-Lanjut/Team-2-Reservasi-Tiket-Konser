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

    public function updateStatus($seatId, $status): Seat
    {
        $seat = Seat::findOrFail($seatId);
        $seat->update(['status' => $status]);
        return $seat;
    }

    public function findById($seatId): ?Seat
    {
        return Seat::find($seatId);
    }

    public function getByVenue($venueId)
    {
        return Seat::where('venue_id', $venueId)->get();
    }

    /**
     * SELECT ... FOR UPDATE — harus dipanggil dalam DB::transaction().
     * Mencegah dua request mengambil seat yang sama secara bersamaan.
     */
    public function findByIdForUpdate(int $seatId): ?Seat
    {
        return Seat::query()->lockForUpdate()->find($seatId);
    }
}

