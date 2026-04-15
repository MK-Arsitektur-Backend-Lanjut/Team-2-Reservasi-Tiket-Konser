<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Ticket;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use Illuminate\Support\Str;

class TicketService
{
    public function __construct(
        private readonly TicketRepositoryInterface $ticketRepository
    ) {
    }

    public function generateForReservation(Reservation $reservation): Ticket
    {
        $existing = $this->ticketRepository->findByReservationId($reservation->id);

        if ($existing) {
            return $existing;
        }

        $ticketCode = $this->generateUniqueCode();

        $qrPayload = json_encode([
            'ticket_code' => $ticketCode,
            'reservation_id' => $reservation->id,
            'user_id' => $reservation->user_id,
            'issued_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        return $this->ticketRepository->create([
            'reservation_id' => $reservation->id,
            'ticket_code' => $ticketCode,
            'qr_code' => base64_encode($qrPayload),
            'issued_at' => now(),
        ]);
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'TIX-'.Str::upper(Str::random(10));
        } while ($this->ticketRepository->existsByTicketCode($code));

        return $code;
    }
}
