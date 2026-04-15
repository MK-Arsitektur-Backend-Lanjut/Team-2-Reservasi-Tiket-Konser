<?php

namespace App\Repositories;

use App\Models\Payment;
use App\Repositories\Interfaces\PaymentRepositoryInterface;
use Illuminate\Support\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function firstOrCreatePendingByReservationId(int $reservationId): Payment
    {
        return Payment::query()->firstOrCreate(
            ['reservation_id' => $reservationId],
            ['status' => 'pending']
        );
    }

    public function save(Payment $payment): bool
    {
        return $payment->save();
    }

    public function markFailedByReservationIds(Collection $reservationIds): int
    {
        if ($reservationIds->isEmpty()) {
            return 0;
        }

        return Payment::query()
            ->whereIn('reservation_id', $reservationIds->all())
            ->where('status', 'pending')
            ->update(['status' => 'failed']);
    }
}
