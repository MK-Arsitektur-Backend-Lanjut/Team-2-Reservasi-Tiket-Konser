<?php

namespace App\Repositories\Interfaces;

use App\Models\QueueToken;

interface QueueTokenRepositoryInterface
{
    /**
     * Buat token antrean baru untuk user di venue tertentu.
     * Jika user sudah memiliki token aktif (belum expired & belum used), kembalikan token tersebut.
     */
    public function findOrCreateActiveToken(int $userId, int $venueId, int $ttlMinutes = 10): QueueToken;

    /**
     * Cari token yang valid: token string cocok, milik user ini, di venue ini,
     * belum expired, dan belum digunakan.
     */
    public function findValidToken(string $token, int $userId, int $venueId): ?QueueToken;

    /**
     * Tandai token sebagai sudah digunakan.
     */
    public function markUsed(QueueToken $queueToken): bool;
}
