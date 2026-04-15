<?php

namespace App\Repositories\Interfaces;

use App\Models\Reservation;
use Illuminate\Support\Collection;

interface ReservationRepositoryInterface
{
    public function findForUpdateById(int $id): ?Reservation;

    public function save(Reservation $reservation): bool;

    public function expireDueReservations(): Collection;
}
