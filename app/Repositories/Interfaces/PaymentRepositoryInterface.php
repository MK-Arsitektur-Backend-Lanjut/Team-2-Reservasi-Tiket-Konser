<?php

namespace App\Repositories\Interfaces;

use App\Models\Payment;
use Illuminate\Support\Collection;

interface PaymentRepositoryInterface
{
    public function firstOrCreatePendingByReservationId(int $reservationId): Payment;

    public function save(Payment $payment): bool;

    public function markFailedByReservationIds(Collection $reservationIds): int;
}
