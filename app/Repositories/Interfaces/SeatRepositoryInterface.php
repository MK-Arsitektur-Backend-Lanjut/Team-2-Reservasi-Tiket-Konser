<?php

namespace App\Repositories\Interfaces;

use App\Models\Seat;

interface SeatRepositoryInterface
{
    public function getAvailableSeats($venueId, $category = null);
    
    // [OPTIMASI: KONTRAK METHOD BARU]
    // Deklarasi method baru untuk mengambil ringkasan jumlah kursi
    public function getSeatSummary($venueId);

    public function updateStatus($seatId, $status);

    public function findById($seatId): ?Seat;

    public function getByVenue($venueId);

    /**
     * Ambil seat dengan row lock (SELECT ... FOR UPDATE) untuk mencegah race condition.
     * Harus dipanggil dalam DB::transaction().
     */
    public function findByIdForUpdate(int $seatId): ?Seat;
}
