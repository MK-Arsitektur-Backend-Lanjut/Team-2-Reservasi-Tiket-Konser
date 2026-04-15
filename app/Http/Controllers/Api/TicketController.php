<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Repositories\Interfaces\TicketRepositoryInterface;
use Illuminate\Http\JsonResponse;

class TicketController extends Controller
{
    public function __construct(
        private readonly TicketRepositoryInterface $ticketRepository
    ) {
    }

    public function showByReservation(Reservation $reservation): JsonResponse
    {
        if ($reservation->user_id !== (int) auth()->id()) {
            return response()->json([
                'status' => false,
                'message' => 'Akses ditolak.',
                'errors' => [
                    'reservation' => ['Reservasi bukan milik user yang login.'],
                ],
            ], 403);
        }

        $ticket = $this->ticketRepository->findByReservationId($reservation->id);

        if (! $ticket) {
            return response()->json([
                'status' => false,
                'message' => 'Tiket belum diterbitkan.',
                'errors' => [
                    'ticket' => ['Lakukan pembayaran terlebih dahulu.'],
                ],
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Tiket ditemukan.',
            'data' => [
                'ticket' => $ticket,
            ],
        ]);
    }
}
