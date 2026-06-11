<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class WarmPaymentCache extends Command
{
    /**
     * Nama dan signature command.
     *
     * @var string
     */
    protected $signature = 'redis:warm-payments
                            {--status= : Warm-up hanya untuk status tertentu (pending|paid|failed)}
                            {--chunk=500 : Jumlah payment per batch query MySQL}';

    /**
     * Deskripsi command.
     *
     * @var string
     */
    protected $description = 'Warm-up Redis cache dari data payment di MySQL. Mengisi cache STRING per reservation dan SET index per status.';

    /** Key prefix cache payment per reservation */
    private const CACHE_KEY_PREFIX = 'payment:reservation:';

    /** Key prefix SET index per status */
    private const STATUS_SET_PREFIX = 'payments:status:';

    /** TTL pending (1 jam) */
    private const TTL_PENDING = 3600;

    /** TTL paid/failed (24 jam) */
    private const TTL_TERMINAL = 86400;

    /**
     * Jalankan command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);
        $statusFilter = $this->option('status');
        $chunkSize = (int) $this->option('chunk');

        // Validasi opsi status jika diberikan
        if ($statusFilter !== null && !in_array($statusFilter, ['pending', 'paid', 'failed'], true)) {
            $this->error("Status tidak valid: '{$statusFilter}'. Gunakan: pending, paid, atau failed.");
            return Command::FAILURE;
        }

        // Cek koneksi Redis
        try {
            Redis::ping();
        } catch (\Exception $e) {
            $this->error('Tidak dapat terhubung ke Redis: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $this->info('=== Warm-up Redis Payment Cache ===');
        $this->newLine();

        // Bersihkan cache yang sudah ada sebelum warm-up
        $this->clearExistingCache($statusFilter);

        // Hitung total payment yang akan di-cache
        $query = Payment::query();
        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }
        $totalPayments = $query->count();

        if ($totalPayments === 0) {
            $this->warn('Tidak ada payment yang ditemukan untuk di-cache.');
            return Command::SUCCESS;
        }

        $this->info("Memproses {$totalPayments} payment...");
        $bar = $this->output->createProgressBar($totalPayments);
        $bar->start();

        // Counter per status
        $stats = ['pending' => 0, 'paid' => 0, 'failed' => 0, 'other' => 0, 'errors' => 0];

        // Proses dalam chunk untuk menghemat memori
        $query = Payment::query()->orderBy('id');
        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        $query->chunk($chunkSize, function ($payments) use ($bar, &$stats) {
            foreach ($payments as $payment) {
                try {
                    $this->cachePayment($payment);

                    // Update counter
                    if (isset($stats[$payment->status])) {
                        $stats[$payment->status]++;
                    } else {
                        $stats['other']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    Log::warning('[WarmPaymentCache] Gagal cache payment.', [
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        // Tampilkan statistik
        $elapsed = round(microtime(true) - $startTime, 2);
        $this->info('=== Hasil Warm-up ===');
        $this->table(
            ['Status', 'Jumlah'],
            [
                ['Pending', $stats['pending']],
                ['Paid', $stats['paid']],
                ['Failed', $stats['failed']],
                ['Lainnya', $stats['other']],
                ['Error', $stats['errors']],
                ['Total', array_sum($stats) - $stats['errors']],
            ]
        );

        $this->info("Waktu eksekusi: {$elapsed} detik");

        if ($stats['errors'] > 0) {
            $this->warn("Ada {$stats['errors']} payment yang gagal di-cache. Cek log untuk detail.");
        }

        $this->newLine();
        $this->info('✓ Warm-up selesai.');

        return Command::SUCCESS;
    }

    /**
     * Bersihkan cache Redis payment yang sudah ada.
     *
     * @param string|null $statusFilter Jika diberikan, hanya bersihkan SET untuk status tersebut
     */
    private function clearExistingCache(?string $statusFilter): void
    {
        $this->comment('Membersihkan cache yang sudah ada...');

        try {
            if ($statusFilter !== null) {
                // Bersihkan hanya SET index untuk status tertentu
                Redis::del(self::STATUS_SET_PREFIX . $statusFilter);
                $this->line("  ✓ SET index '{$statusFilter}' dibersihkan.");
            } else {
                // Bersihkan semua SET index
                foreach (['pending', 'paid', 'failed'] as $status) {
                    Redis::del(self::STATUS_SET_PREFIX . $status);
                }
                $this->line('  ✓ Semua SET index dibersihkan.');

                // Bersihkan cache STRING payment:reservation:*
                // Menggunakan SCAN untuk menghindari blocking KEYS pada production
                $cursor = null;
                $prefix = config('database.redis.options.prefix', '');
                $pattern = $prefix . self::CACHE_KEY_PREFIX . '*';
                $deletedCount = 0;

                do {
                    // SCAN returns [cursor, [keys]]
                    $result = Redis::scan($cursor, ['match' => $pattern, 'count' => 200]);

                    if ($result === false) {
                        break;
                    }

                    // Unpack SCAN result — format tergantung driver
                    if (is_array($result)) {
                        $cursor = $result[0] ?? null;
                        $keys = $result[1] ?? [];
                    } else {
                        break;
                    }

                    if (!empty($keys)) {
                        // Strip prefix sebelum delete karena Redis facade menambahkan prefix otomatis
                        $keysWithoutPrefix = array_map(function ($key) use ($prefix) {
                            return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
                        }, $keys);

                        Redis::del(...$keysWithoutPrefix);
                        $deletedCount += count($keys);
                    }
                } while ($cursor != 0 && $cursor !== null);

                $this->line("  ✓ {$deletedCount} cache STRING payment dibersihkan.");
            }
        } catch (\Exception $e) {
            $this->warn('  Gagal membersihkan cache: ' . $e->getMessage());
            Log::warning('[WarmPaymentCache] Gagal membersihkan cache saat warm-up.', [
                'error' => $e->getMessage(),
            ]);
        }

        $this->newLine();
    }

    /**
     * Simpan satu payment ke Redis cache (STRING + SET index).
     */
    private function cachePayment(Payment $payment): void
    {
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

        // Tambahkan ke SET index berdasarkan status
        if (in_array($payment->status, ['pending', 'paid', 'failed'], true)) {
            Redis::sadd(self::STATUS_SET_PREFIX . $payment->status, $payment->id);
        }
    }

    /**
     * Tentukan TTL berdasarkan status.
     */
    private function getTtlForStatus(string $status): int
    {
        return match ($status) {
            'pending' => self::TTL_PENDING,
            default => self::TTL_TERMINAL,
        };
    }
}
