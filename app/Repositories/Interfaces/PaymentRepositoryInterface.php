<?php

namespace App\Repositories\Interfaces;

use App\Models\Payment;
use Illuminate\Support\Collection;

interface PaymentRepositoryInterface
{
    /**
     * Cari payment pending berdasarkan reservation_id, buat baru jika belum ada.
     */
    public function firstOrCreatePendingByReservationId(int $reservationId): Payment;

    /**
     * Simpan perubahan pada payment (write-through: MySQL + Redis).
     */
    public function save(Payment $payment): bool;

    /**
     * Tandai semua payment pending milik reservation_ids sebagai failed.
     */
    public function markFailedByReservationIds(Collection $reservationIds): int;

    /**
     * Ambil semua payment berdasarkan status dari Redis SET index.
     * Fallback ke MySQL jika Redis kosong/tidak tersedia.
     */
    public function getPaymentsByStatus(string $status): Collection;

    /**
     * Hitung jumlah payment berdasarkan status dari Redis SET (SCARD).
     * Fallback ke MySQL COUNT jika Redis tidak tersedia.
     */
    public function getPaymentCountByStatus(string $status): int;

    /**
     * Ambil payment dari Redis cache berdasarkan reservation_id.
     * Fallback ke MySQL dan populate cache jika miss.
     */
    public function getCachedPaymentByReservationId(int $reservationId): ?Payment;
}
