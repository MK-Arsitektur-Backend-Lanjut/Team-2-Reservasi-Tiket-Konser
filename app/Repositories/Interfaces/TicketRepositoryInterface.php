<?php

namespace App\Repositories\Interfaces;

use App\Models\Ticket;

interface TicketRepositoryInterface
{
    public function findByReservationId(int $reservationId): ?Ticket;

    public function existsByTicketCode(string $ticketCode): bool;

    public function create(array $payload): Ticket;
}
