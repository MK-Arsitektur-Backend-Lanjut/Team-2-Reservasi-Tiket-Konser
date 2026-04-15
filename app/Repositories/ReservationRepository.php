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
}
