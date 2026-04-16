<?php

namespace App\Repositories;

use App\Models\QueueToken;
use App\Repositories\Interfaces\QueueTokenRepositoryInterface;
use Illuminate\Support\Str;

class QueueTokenRepository implements QueueTokenRepositoryInterface
{
    /**
     * Cari token aktif yang masih bisa dipakai (belum expired, belum used).
     * Jika ada, kembalikan token tersebut. Jika tidak, buat token baru.
     */
    public function findOrCreateActiveToken(int $userId, int $venueId, int $ttlMinutes = 10): QueueToken
    {
        $existing = QueueToken::query()
            ->where('user_id', $userId)
            ->where('venue_id', $venueId)
            ->whereNull('used_at')
            ->where('expired_at', '>', now())
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        return QueueToken::query()->create([
            'user_id'    => $userId,
            'venue_id'   => $venueId,
            'token'      => 'QT-' . Str::upper(Str::random(12)),
            'used_at'    => null,
            'expired_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    /**
     * Cari token yang valid untuk proses hold seat:
     * - String token cocok
     * - Milik user ini
     * - Di venue ini
     * - Belum expired
     * - Belum digunakan
     */
    public function findValidToken(string $token, int $userId, int $venueId): ?QueueToken
    {
        return QueueToken::query()
            ->where('token', $token)
            ->where('user_id', $userId)
            ->where('venue_id', $venueId)
            ->whereNull('used_at')
            ->where('expired_at', '>', now())
            ->first();
    }

    /**
     * Tandai token sebagai sudah digunakan (1x-pakai).
     */
    public function markUsed(QueueToken $queueToken): bool
    {
        $queueToken->used_at = now();
        return $queueToken->save();
    }
}
