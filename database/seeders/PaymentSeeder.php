<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        $target = 5000;
        $now = now();
        $availableBefore = $this->availableReservationIds($target)->count();

        if ($availableBefore < $target) {
            $this->createAdditionalReservations($target - $availableBefore);
        }

        $reservationIds = $this->availableReservationIds($target);

        $available = $reservationIds->count();
        $statuses = ['paid', 'pending', 'failed'];
        $weights = [60, 25, 15];
        $rows = [];

        foreach ($reservationIds as $reservationId) {
            $status = $this->pickWeightedStatus($statuses, $weights);

            $rows[] = [
                'reservation_id' => $reservationId,
                'status' => $status,
                'paid_at' => $status === 'paid' ? $now->copy()->subMinutes(random_int(1, 10080)) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('payments')->insert($chunk);
            }
        }

        $this->command?->info("PaymentSeeder inserted {$available} rows (target: {$target}).");
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function availableReservationIds(int $limit)
    {
        return DB::table('reservations')
            ->leftJoin('payments', 'payments.reservation_id', '=', 'reservations.id')
            ->whereNull('payments.id')
            ->orderBy('reservations.id')
            ->limit($limit)
            ->pluck('reservations.id')
            ->values();
    }

    private function createAdditionalReservations(int $needed): void
    {
        if ($needed <= 0) {
            return;
        }

        $userIds = $this->resolveUserIds();
        $seatIds = $this->resolveSeatIds();

        if (empty($userIds) || empty($seatIds)) {
            throw new RuntimeException('Cannot create additional reservations: no source users or seats.');
        }

        $now = now();
        $rows = [];

        for ($i = 0; $i < $needed; $i++) {
            $rows[] = [
                'user_id' => $userIds[array_rand($userIds)],
                'seat_id' => $seatIds[array_rand($seatIds)],
                'status' => 'confirmed',
                'expired_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('reservations')->insert($chunk);
        }
    }

    /**
     * @return array<int, int>
     */
    private function resolveUserIds(): array
    {
        if (Schema::hasTable('users')) {
            $userIds = DB::table('users')->pluck('id')->all();
            if (! empty($userIds)) {
                return $userIds;
            }
        }

        return DB::table('reservations')
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function resolveSeatIds(): array
    {
        if (Schema::hasTable('seats')) {
            $seatIds = DB::table('seats')->pluck('id')->all();
            if (! empty($seatIds)) {
                return $seatIds;
            }
        }

        return DB::table('reservations')
            ->whereNotNull('seat_id')
            ->pluck('seat_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $statuses
     * @param array<int, int> $weights
     */
    private function pickWeightedStatus(array $statuses, array $weights): string
    {
        $sum = array_sum($weights);
        $roll = random_int(1, $sum);
        $cursor = 0;

        foreach ($statuses as $index => $status) {
            $cursor += $weights[$index];
            if ($roll <= $cursor) {
                return $status;
            }
        }

        return $statuses[0];
    }
}
