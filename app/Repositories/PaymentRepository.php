<?php

namespace App\Repositories;

use App\Models\Payment;
use App\Repositories\Interfaces\PaymentRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PaymentRepository implements PaymentRepositoryInterface
{
    // =====================================================================
    // Konstanta key pattern Redis
    // =====================================================================

    /** Key cache payment per reservation: payment:reservation:{reservationId} */
    private const CACHE_KEY_PREFIX = 'payment:reservation:';

    /** Key SET index per status: payments:status:{status} */
    private const STATUS_SET_PREFIX = 'payments:status:';

    /** Daftar status yang valid untuk indexing */
    private const VALID_STATUSES = ['pending', 'paid', 'failed'];

    // =====================================================================
    // TTL (Time-To-Live) dalam detik
    // =====================================================================

    /** TTL untuk payment berstatus pending (1 jam) */
    private const TTL_PENDING = 3600;

    /** TTL untuk payment berstatus paid/failed (24 jam) */
    private const TTL_TERMINAL = 86400;

    // =====================================================================
    // Method utama (kontrak interface asli)
    // =====================================================================

    /**
     * Cari payment pending berdasarkan reservation_id, buat baru jika belum ada.
     *
     * Flow:
     * 1. Cek Redis cache terlebih dahulu
     * 2. Jika hit → return langsung (tanpa query MySQL)
     * 3. Jika miss → firstOrCreate di MySQL, lalu populate cache
     */
    public function firstOrCreatePendingByReservationId(int $reservationId): Payment
    {
        // Coba ambil dari cache Redis terlebih dahulu
        $cached = $this->getFromCache($reservationId);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss — query MySQL
        $payment = Payment::query()->firstOrCreate(
            ['reservation_id' => $reservationId],
            ['status' => 'pending']
        );

        // Populate cache setelah query MySQL
        $this->cachePayment($payment);

        return $payment;
    }

    /**
     * Simpan payment ke MySQL, lalu update Redis cache (write-through).
     *
     * Flow:
     * 1. Deteksi apakah status berubah (untuk update SET index)
     * 2. Save ke MySQL terlebih dahulu (source of truth)
     * 3. Update Redis cache string + pindahkan antar SET index jika status berubah
     */
    public function save(Payment $payment): bool
    {
        // Catat status lama sebelum save (untuk memindahkan antar SET index)
        $oldStatus = $payment->getOriginal('status');

        // Write ke MySQL (source of truth)
        $result = $payment->save();

        if ($result) {
            // Update Redis cache setelah MySQL berhasil
            $this->cachePayment($payment);

            // Pindahkan antar SET index jika status berubah
            if ($oldStatus !== null && $oldStatus !== $payment->status) {
                $this->movePaymentBetweenSets($payment->id, $oldStatus, $payment->status);
            }
        }

        return $result;
    }

    /**
     * Tandai semua payment pending milik reservation_ids sebagai failed.
     *
     * Flow:
     * 1. Ambil semua payment yang akan di-update (untuk mendapatkan ID-nya)
     * 2. Bulk update MySQL
     * 3. Invalidate & rebuild cache untuk setiap payment yang terdampak
     */
    public function markFailedByReservationIds(Collection $reservationIds): int
    {
        if ($reservationIds->isEmpty()) {
            return 0;
        }

        // Ambil payment yang akan di-update untuk keperluan cache invalidation
        $affectedPayments = Payment::query()
            ->whereIn('reservation_id', $reservationIds->all())
            ->where('status', 'pending')
            ->get(['id', 'reservation_id']);

        // Bulk update MySQL
        $updatedCount = Payment::query()
            ->whereIn('reservation_id', $reservationIds->all())
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        // Update Redis cache untuk setiap payment yang terdampak
        if ($updatedCount > 0) {
            $this->invalidateAndRebuildBatch($affectedPayments);
        }

        return $updatedCount;
    }

    // =====================================================================
    // Method baru (Redis-backed queries)
    // =====================================================================

    /**
     * Ambil semua payment berdasarkan status dari Redis SET index.
     * Fallback ke MySQL jika Redis tidak tersedia atau SET kosong.
     *
     * @param string $status Status payment (pending|paid|failed)
     * @return Collection<int, Payment>
     */
    public function getPaymentsByStatus(string $status): Collection
    {
        try {
            $setKey = self::STATUS_SET_PREFIX . $status;
            $paymentIds = Redis::smembers($setKey);

            if (!empty($paymentIds)) {
                // Ambil detail setiap payment dari cache atau MySQL
                return collect($paymentIds)
                    ->map(function ($paymentId) {
                        return $this->getPaymentByIdFromCacheOrDb((int) $paymentId);
                    })
                    ->filter() // Hapus null values
                    ->values();
            }
        } catch (\Exception $e) {
            Log::warning('[PaymentRepository] Redis error pada getPaymentsByStatus, fallback ke MySQL.', [
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback ke MySQL
        return Payment::query()
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Hitung jumlah payment berdasarkan status dari Redis SET (SCARD).
     * Fallback ke MySQL COUNT jika Redis tidak tersedia.
     *
     * @param string $status Status payment (pending|paid|failed)
     */
    public function getPaymentCountByStatus(string $status): int
    {
        try {
            $setKey = self::STATUS_SET_PREFIX . $status;
            $count = Redis::scard($setKey);

            // Jika SET ada dan memiliki member, gunakan nilai dari Redis
            if ($count > 0) {
                return (int) $count;
            }
        } catch (\Exception $e) {
            Log::warning('[PaymentRepository] Redis error pada getPaymentCountByStatus, fallback ke MySQL.', [
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback ke MySQL
        return Payment::query()
            ->where('status', $status)
            ->count();
    }

    /**
     * Ambil payment dari Redis cache berdasarkan reservation_id.
     * Fallback ke MySQL dan populate cache jika miss.
     *
     * @param int $reservationId ID reservasi
     * @return Payment|null Null jika payment tidak ditemukan di cache maupun MySQL
     */
    public function getCachedPaymentByReservationId(int $reservationId): ?Payment
    {
        // Coba ambil dari Redis cache
        $cached = $this->getFromCache($reservationId);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss — fallback ke MySQL
        $payment = Payment::query()
            ->where('reservation_id', $reservationId)
            ->first();

        // Populate cache jika ditemukan di MySQL
        if ($payment !== null) {
            $this->cachePayment($payment);
        }

        return $payment;
    }

    // =====================================================================
    // Helper methods (private) — operasi Redis internal
    // =====================================================================

    /**
     * Simpan payment ke Redis cache (STRING) dan tambahkan ke SET index.
     *
     * Key STRING: payment:reservation:{reservationId} → JSON payload
     * Key SET:    payments:status:{status} → {paymentId, ...}
     */
    private function cachePayment(Payment $payment): void
    {
        try {
            $cacheKey = self::CACHE_KEY_PREFIX . $payment->reservation_id;
            $ttl = $this->getTtlForStatus($payment->status);

            // Serialize payment ke JSON dan simpan dengan TTL
            $payload = json_encode([
                'id' => $payment->id,
                'reservation_id' => $payment->reservation_id,
                'status' => $payment->status,
                'paid_at' => $payment->paid_at?->toISOString(),
                'created_at' => $payment->created_at?->toISOString(),
                'updated_at' => $payment->updated_at?->toISOString(),
            ]);

            Redis::setex($cacheKey, $ttl, $payload);

            // Tambahkan payment_id ke SET index berdasarkan status
            if (in_array($payment->status, self::VALID_STATUSES, true)) {
                Redis::sadd(self::STATUS_SET_PREFIX . $payment->status, $payment->id);
            }
        } catch (\Exception $e) {
            // Log error tapi jangan throw — MySQL sudah jadi source of truth
            Log::warning('[PaymentRepository] Gagal menyimpan cache Redis.', [
                'reservation_id' => $payment->reservation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ambil payment dari Redis cache dan hydrate menjadi Eloquent model.
     *
     * @return Payment|null Null jika cache miss atau data corrupt
     */
    private function getFromCache(int $reservationId): ?Payment
    {
        try {
            $cacheKey = self::CACHE_KEY_PREFIX . $reservationId;
            $cached = Redis::get($cacheKey);

            if ($cached !== null && $cached !== false) {
                return $this->hydratePaymentFromCache($cached);
            }
        } catch (\Exception $e) {
            Log::warning('[PaymentRepository] Gagal membaca cache Redis, fallback ke MySQL.', [
                'reservation_id' => $reservationId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Pindahkan payment_id dari satu SET index ke SET index lain.
     * Dipanggil saat status payment berubah (misal pending → paid).
     */
    private function movePaymentBetweenSets(int $paymentId, string $oldStatus, string $newStatus): void
    {
        try {
            // Hapus dari SET lama
            if (in_array($oldStatus, self::VALID_STATUSES, true)) {
                Redis::srem(self::STATUS_SET_PREFIX . $oldStatus, $paymentId);
            }

            // Tambahkan ke SET baru
            if (in_array($newStatus, self::VALID_STATUSES, true)) {
                Redis::sadd(self::STATUS_SET_PREFIX . $newStatus, $paymentId);
            }
        } catch (\Exception $e) {
            Log::warning('[PaymentRepository] Gagal memindahkan payment antar SET index Redis.', [
                'payment_id' => $paymentId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Batch invalidate cache & rebuild untuk kumpulan payment yang status-nya
     * berubah dari pending ke failed (digunakan oleh markFailedByReservationIds).
     */
    private function invalidateAndRebuildBatch(Collection $affectedPayments): void
    {
        foreach ($affectedPayments as $paymentData) {
            try {
                // Hapus cache lama
                $cacheKey = self::CACHE_KEY_PREFIX . $paymentData->reservation_id;
                Redis::del($cacheKey);

                // Pindahkan dari SET pending ke SET failed
                $this->movePaymentBetweenSets($paymentData->id, 'pending', 'failed');

                // Reload dari MySQL dan rebuild cache dengan status terbaru
                $freshPayment = Payment::query()->find($paymentData->id);
                if ($freshPayment !== null) {
                    $this->cachePayment($freshPayment);
                }
            } catch (\Exception $e) {
                Log::warning('[PaymentRepository] Gagal rebuild cache batch.', [
                    'payment_id' => $paymentData->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ambil payment berdasarkan ID, cek dari cache terlebih dahulu.
     * Digunakan oleh getPaymentsByStatus() untuk mengambil detail per payment.
     *
     * @return Payment|null
     */
    private function getPaymentByIdFromCacheOrDb(int $paymentId): ?Payment
    {
        // Query MySQL langsung by ID (sangat cepat dengan primary key)
        $payment = Payment::query()->find($paymentId);

        if ($payment !== null) {
            // Populate cache jika belum ada
            $cacheKey = self::CACHE_KEY_PREFIX . $payment->reservation_id;
            try {
                if (Redis::get($cacheKey) === null || Redis::get($cacheKey) === false) {
                    $this->cachePayment($payment);
                }
            } catch (\Exception $e) {
                // Abaikan error Redis — data dari MySQL sudah valid
            }
        }

        return $payment;
    }

    /**
     * Deserialize JSON cache menjadi Payment Eloquent model.
     * Model di-hydrate tanpa menyimpannya (exists = true supaya tidak dianggap baru).
     *
     * @param string $json JSON payload dari Redis
     * @return Payment|null Null jika JSON corrupt
     */
    private function hydratePaymentFromCache(string $json): ?Payment
    {
        $data = json_decode($json, true);

        if ($data === null || !isset($data['id'])) {
            return null;
        }

        $payment = new Payment();
        $payment->id = $data['id'];
        $payment->reservation_id = $data['reservation_id'];
        $payment->status = $data['status'];
        $payment->paid_at = isset($data['paid_at']) ? \Carbon\Carbon::parse($data['paid_at']) : null;
        $payment->created_at = isset($data['created_at']) ? \Carbon\Carbon::parse($data['created_at']) : null;
        $payment->updated_at = isset($data['updated_at']) ? \Carbon\Carbon::parse($data['updated_at']) : null;

        // Tandai model sebagai "sudah ada di DB" agar save() melakukan UPDATE, bukan INSERT
        $payment->exists = true;

        return $payment;
    }

    /**
     * Tentukan TTL berdasarkan status payment.
     *
     * - pending: 1 jam (3600s) — bersifat sementara, bisa berubah
     * - paid/failed: 24 jam (86400s) — status terminal, lebih stabil
     *
     * @param string $status Status payment
     * @return int TTL dalam detik
     */
    private function getTtlForStatus(string $status): int
    {
        return match ($status) {
            'pending' => self::TTL_PENDING,
            default => self::TTL_TERMINAL,
        };
    }
}
