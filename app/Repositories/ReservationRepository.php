<?php

namespace App\Repositories;

use App\Models\Reservation;
use App\Repositories\Interfaces\ReservationRepositoryInterface;
use Illuminate\Support\Collection;

class ReservationRepository implements ReservationRepositoryInterface
{
    public function findForUpdateById(int $id): ?Reservation
    {
        return Reservation::query()->lockForUpdate()->find($id);
    }

    public function save(Reservation $reservation): bool
    {
        return $reservation->save();
    }

    public function create(array $data): Reservation
    {
        return Reservation::query()->create($data);
    }

    public function expireDueReservations(): Collection
    {
        $now = now();

        $expired = Reservation::query()
            ->where('status', 'holding')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', $now)
            ->lockForUpdate()
            ->get();

        foreach ($expired as $reservation) {
            $reservation->status = 'expired';
            $reservation->save();
        }

        return $expired;
    }

    public function lockedForReservations(): Collection
    {
        return Reservation::query()
            ->whereIn('status', ['holding', 'confirmed'])
            ->lockForUpdate()
            ->get();
    }

    /**
     * Cek apakah user sudah punya reservasi holding/confirmed untuk seat tertentu.
     * Digunakan untuk mencegah user memesan kursi yang sama dua kali.
     */
    public function findActiveByUserAndSeat(int $userId, int $seatId): ?Reservation
    {
        return Reservation::query()
            ->where('user_id', $userId)
            ->where('seat_id', $seatId)
            ->whereIn('status', ['holding', 'confirmed'])
            ->first();
    }

    /**
     * Ambil semua reservasi milik user beserta relasi seat.
     */
    public function findByUser(int $userId): Collection
    {
        return Reservation::query()
            ->where('user_id', $userId)
            ->with(['seat', 'payment', 'ticket'])
            ->latest()
            ->get();
    }

    /**
     * Cari reservasi berdasarkan ID dan pastikan milik user ini.
     */
    public function findByIdAndUser(int $id, int $userId): ?Reservation
    {
        return Reservation::query()
            ->where('id', $id)
            ->where('user_id', $userId)
            ->with(['seat', 'payment', 'ticket'])
            ->first();
    }
}

