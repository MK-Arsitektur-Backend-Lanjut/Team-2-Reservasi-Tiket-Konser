<?php

namespace App\Repositories;

use App\Models\Ticket;
use App\Repositories\Interfaces\TicketRepositoryInterface;

class TicketRepository implements TicketRepositoryInterface
{
    public function findByReservationId(int $reservationId): ?Ticket
    {
        return Ticket::query()->where('reservation_id', $reservationId)->first();
    }

    public function existsByTicketCode(string $ticketCode): bool
    {
        return Ticket::query()->where('ticket_code', $ticketCode)->exists();
    }

    public function create(array $payload): Ticket
    {
        return Ticket::query()->create($payload);
    }
}
