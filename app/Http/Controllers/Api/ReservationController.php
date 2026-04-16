<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reservation\HoldSeatRequest;
use App\Http\Requests\Reservation\RequestQueueTokenRequest;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService
    ) {}

    // -------------------------------------------------------------------------
    // POST /reservations/queue-token
    // -------------------------------------------------------------------------

    /**
     * Minta token antrean untuk venue tertentu.
     * Jika token aktif sudah ada, token yang sama dikembalikan (idempotent).
     *
     * @response {
     *   "status": true,
     *   "message": "Token antrean berhasil dibuat.",
     *   "data": { "token": "QT-XXXX", "expired_at": "2026-..." }
     * }
     */
    public function requestToken(RequestQueueTokenRequest $request): JsonResponse
    {
        try {
            $queueToken = $this->reservationService->requestQueueToken(
                (int) auth()->id(),
                (int) $request->integer('venue_id')
            );

            return response()->json([
                'status'  => true,
                'message' => 'Token antrean berhasil diterbitkan.',
                'data'    => [
                    'token'      => $queueToken->token,
                    'expired_at' => $queueToken->expired_at->toIso8601String(),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // -------------------------------------------------------------------------
    // POST /reservations/hold
    // -------------------------------------------------------------------------

    /**
     * Kunci kursi selama 10 menit menggunakan token antrean yang valid.
     * Race condition dicegah dengan SELECT FOR UPDATE di dalam DB::transaction.
     *
     * @response {
     *   "status": true,
     *   "message": "Kursi berhasil di-hold.",
     *   "data": { "reservation": {...}, "expired_at": "..." }
     * }
     */
    public function hold(HoldSeatRequest $request): JsonResponse
    {
        try {
            $reservation = $this->reservationService->holdSeat(
                userId:   (int) auth()->id(),
                venueId:  (int) $request->integer('venue_id'),
                seatId:   (int) $request->integer('seat_id'),
                token:    (string) $request->string('queue_token'),
            );

            return response()->json([
                'status'  => true,
                'message' => 'Kursi berhasil di-hold. Selesaikan pembayaran sebelum ' . $reservation->expired_at->toIso8601String() . '.',
                'data'    => [
                    'reservation' => $reservation,
                    'expired_at'  => $reservation->expired_at->toIso8601String(),
                ],
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'errors'  => ['reservation' => [$e->getMessage()]],
            ], 422);
        }
    }

    // -------------------------------------------------------------------------
    // DELETE /reservations/{id}/release
    // -------------------------------------------------------------------------

    /**
     * Batalkan hold secara manual sebelum expired.
     * Seat akan dikembalikan ke status available.
     */
    public function release(int $id): JsonResponse
    {
        try {
            $this->reservationService->releaseHold((int) auth()->id(), $id);

            return response()->json([
                'status'  => true,
                'message' => 'Reservasi berhasil dibatalkan, kursi kembali tersedia.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
                'errors'  => ['reservation' => [$e->getMessage()]],
            ], 422);
        }
    }

    // -------------------------------------------------------------------------
    // GET /reservations
    // -------------------------------------------------------------------------

    /**
     * Daftar semua reservasi milik user yang sedang login.
     */
    public function index(): JsonResponse
    {
        $reservations = $this->reservationService->getUserReservations((int) auth()->id());

        return response()->json([
            'status'  => true,
            'message' => 'Berhasil mengambil data reservasi.',
            'data'    => $reservations,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /reservations/{id}
    // -------------------------------------------------------------------------

    /**
     * Detail satu reservasi milik user yang sedang login.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $reservation = $this->reservationService->getReservationDetail((int) auth()->id(), $id);

            return response()->json([
                'status'  => true,
                'message' => 'Berhasil mengambil detail reservasi.',
                'data'    => $reservation,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 404);
        }
    }
}
