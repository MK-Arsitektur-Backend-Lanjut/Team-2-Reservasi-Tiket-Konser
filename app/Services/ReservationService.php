<?php

namespace App\Services;

use App\Models\QueueToken;
use App\Models\Reservation;
use App\Repositories\Interfaces\PaymentRepositoryInterface;
use App\Repositories\Interfaces\QueueTokenRepositoryInterface;
use App\Repositories\Interfaces\ReservationRepositoryInterface;
use App\Repositories\Interfaces\SeatRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReservationService
{
    /**
     * Durasi hold kursi default (menit).
     */
    private const DEFAULT_HOLD_MINUTES = 10;

    /**
     * Durasi validitas token antrean (menit).
     */
    private const TOKEN_TTL_MINUTES = 10;

    public function __construct(
        private readonly ReservationRepositoryInterface $reservationRepository,
        private readonly SeatRepositoryInterface        $seatRepository,
        private readonly QueueTokenRepositoryInterface  $queueTokenRepository,
        private readonly PaymentRepositoryInterface     $paymentRepository,
    ) {}

    // -------------------------------------------------------------------------
    // Queue Token
    // -------------------------------------------------------------------------

    /**
     * Minta token antrean untuk user di venue tertentu.
     * Jika user sudah memiliki token aktif, token yang sama dikembalikan.
     *
     * @throws RuntimeException
     */
    public function requestQueueToken(int $userId, int $venueId): QueueToken
    {
        return $this->queueTokenRepository->findOrCreateActiveToken(
            $userId,
            $venueId,
            self::TOKEN_TTL_MINUTES
        );
    }

    // -------------------------------------------------------------------------
    // Hold Seat
    // -------------------------------------------------------------------------

    /**
     * Kunci (hold) kursi selama $holdMinutes menit untuk user yang membawa token antrean valid.
     *
     * Alur:
     *  1. Validasi queue token
     *  2. DB::transaction + SELECT FOR UPDATE pada seat  ← cegah race condition
     *  3. Pastikan seat masih available
     *  4. Pastikan user belum hold kursi ini
     *  5. Update seat → hold
     *  6. Buat Reservation dengan expired_at
     *  7. Tandai token sebagai used (1x-pakai)
     *
     * @throws RuntimeException
     */
    public function holdSeat(
        int    $userId,
        int    $venueId,
        int    $seatId,
        string $token,
        int    $holdMinutes = self::DEFAULT_HOLD_MINUTES
    ): Reservation {
        // --- 1. Validasi Queue Token (di luar transaksi, baca saja) ---
        $queueToken = $this->queueTokenRepository->findValidToken($token, $userId, $venueId);

        if (! $queueToken) {
            throw new RuntimeException('Token antrean tidak valid, sudah digunakan, atau sudah expired.');
        }

        // --- 2-7. Proses dalam satu transaksi dengan row-level lock ---
        return DB::transaction(function () use ($userId, $seatId, $holdMinutes, $queueToken): Reservation {

            // SELECT ... FOR UPDATE: hanya satu proses yang bisa melanjutkan untuk seat ini
            $seat = $this->seatRepository->findByIdForUpdate($seatId);

            if (! $seat) {
                throw new RuntimeException('Kursi tidak ditemukan.');
            }

            // --- 3. Cek status kursi ---
            if ($seat->status !== 'available') {
                throw new RuntimeException('Kursi sudah tidak tersedia (sedang di-hold atau sudah terjual).');
            }

            // --- 4. Cek apakah user sudah memesan kursi ini ---
            $existing = $this->reservationRepository->findActiveByUserAndSeat($userId, $seatId);
            if ($existing) {
                throw new RuntimeException('Kamu sudah memiliki reservasi aktif untuk kursi ini.');
            }

            // --- 5. Hold seat ---
            $seat->status = 'hold';
            $seat->save();

            // --- 6. Buat Reservation ---
            $reservation = $this->reservationRepository->create([
                'user_id'    => $userId,
                'seat_id'    => $seatId,
                'status'     => 'holding',
                'expired_at' => now()->addMinutes($holdMinutes),
            ]);

            // --- 7. Tandai token sebagai used ---
            $this->queueTokenRepository->markUsed($queueToken);

            return $reservation->load(['seat', 'seat.venue']);
        });
    }

    // -------------------------------------------------------------------------
    // Release Hold (manual cancel oleh user)
    // -------------------------------------------------------------------------

    /**
     * Lepas hold kursi secara manual oleh user sebelum expired.
     *
     * @throws RuntimeException
     */
    public function releaseHold(int $userId, int $reservationId): bool
    {
        return DB::transaction(function () use ($userId, $reservationId): bool {
            $reservation = $this->reservationRepository->findForUpdateById($reservationId);

            if (! $reservation || $reservation->user_id !== $userId) {
                throw new RuntimeException('Reservasi tidak ditemukan.');
            }

            if ($reservation->status !== 'holding') {
                throw new RuntimeException('Reservasi tidak dalam status holding, tidak bisa dibatalkan.');
            }

            // Kembalikan seat ke available
            $seat = $this->seatRepository->findByIdForUpdate($reservation->seat_id);
            if ($seat && $seat->status === 'hold') {
                $seat->status = 'available';
                $seat->save();
            }

            $reservation->status = 'cancelled';
            return $this->reservationRepository->save($reservation);
        });
    }

    // -------------------------------------------------------------------------
    // List & Detail
    // -------------------------------------------------------------------------

    /**
     * Ambil semua reservasi milik user.
     */
    public function getUserReservations(int $userId): Collection
    {
        return $this->reservationRepository->findByUser($userId);
    }

    /**
     * Detail satu reservasi milik user.
     *
     * @throws RuntimeException
     */
    public function getReservationDetail(int $userId, int $reservationId): Reservation
    {
        $reservation = $this->reservationRepository->findByIdAndUser($reservationId, $userId);

        if (! $reservation) {
            throw new RuntimeException('Reservasi tidak ditemukan.');
        }

        return $reservation;
    }

    // -------------------------------------------------------------------------
    // Auto-Expire (dipanggil Scheduler)
    // -------------------------------------------------------------------------

    /**
     * Expire semua reservasi yang hold-nya sudah habis.
     * Seat dikembalikan ke available, payment di-mark failed.
     * Didelegasikan ke PaymentService agar auto-cancel berjalan lengkap.
     */
    public function expireHolds(): int
    {
        return DB::transaction(function (): int {
            $expired = $this->reservationRepository->expireDueReservations();

            foreach ($expired as $reservation) {
                $seat = $this->seatRepository->findByIdForUpdate($reservation->seat_id);
                if ($seat && $seat->status === 'hold') {
                    $seat->status = 'available';
                    $seat->save();
                }
            }
            
            // Mark corresponding pending payments as failed
            $this->paymentRepository->markFailedByReservationIds($expired->pluck('id'));

            return $expired->count();
        });
    }
}
