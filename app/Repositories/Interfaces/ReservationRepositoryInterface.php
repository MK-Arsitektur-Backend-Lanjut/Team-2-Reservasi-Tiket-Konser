<?php

namespace App\Repositories\Interfaces;

use App\Models\Reservation;
use Illuminate\Support\Collection;

interface ReservationRepositoryInterface
{
    public function findForUpdateById(int $id): ?Reservation;

    public function save(Reservation $reservation): bool;

    public function create(array $data): Reservation;

    public function expireDueReservations(): Collection;

    public function lockedForReservations(): Collection;

    /**
     * Cek apakah user sudah memiliki reservasi aktif (holding/confirmed) untuk seat tertentu.
     */
    public function findActiveByUserAndSeat(int $userId, int $seatId): ?Reservation;

    /**
     * Ambil semua reservasi milik user, urutkan terbaru.
     */
    public function findByUser(int $userId): Collection;

    /**
     * Cari reservasi berdasarkan id milik user tertentu.
     */
    public function findByIdAndUser(int $id, int $userId): ?Reservation;
}
