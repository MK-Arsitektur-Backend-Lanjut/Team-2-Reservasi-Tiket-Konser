<?php

namespace App\Services;

use App\Models\Ticket;
use App\Repositories\Interfaces\PaymentRepositoryInterface;
use App\Repositories\Interfaces\ReservationRepositoryInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    public function __construct(
        private readonly ReservationRepositoryInterface $reservationRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly TicketService $ticketService
    ) {
    }

    public function payReservation(int $userId, int $reservationId): Ticket
    {
        return DB::transaction(function () use ($userId, $reservationId): Ticket {
            $reservation = $this->reservationRepository->findForUpdateById($reservationId);

            if (! $reservation || $reservation->user_id !== $userId) {
                throw new RuntimeException('Reservasi tidak ditemukan.');
            }

            if ($reservation->status === 'expired' || ($reservation->expired_at && $reservation->expired_at->isPast())) {
                $reservation->status = 'expired';
                $this->reservationRepository->save($reservation);
                throw new RuntimeException('Reservasi sudah expired.');
            }

            if (! in_array($reservation->status, ['holding', 'confirmed'], true)) {
                throw new RuntimeException('Status reservasi tidak valid untuk pembayaran.');
            }

            $payment = $this->paymentRepository->firstOrCreatePendingByReservationId($reservation->id);

            if ($payment->status === 'paid') {
                return $this->ticketService->generateForReservation($reservation);
            }

            $payment->status = 'paid';
            $payment->paid_at = now();
            $this->paymentRepository->save($payment);

            $reservation->status = 'confirmed';
            $this->reservationRepository->save($reservation);

            return $this->ticketService->generateForReservation($reservation);
        });
    }

    public function autoCancelExpiredReservations(): int
    {
        return DB::transaction(function (): int {
            $expiredReservations = $this->reservationRepository->expireDueReservations();
            $this->paymentRepository->markFailedByReservationIds($expiredReservations->pluck('id'));

            return $expiredReservations->count();
        });
    }
}
