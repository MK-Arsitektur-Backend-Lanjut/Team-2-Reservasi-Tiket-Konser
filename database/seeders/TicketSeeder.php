<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketSeeder extends Seeder
{
    public function run(): void
    {
        $target = 1000;
        $now = now();

        $reservationIds = DB::table('payments')
            ->join('reservations', 'reservations.id', '=', 'payments.reservation_id')
            ->leftJoin('tickets', 'tickets.reservation_id', '=', 'reservations.id')
            ->whereNull('tickets.id')
            ->where('payments.status', 'paid')
            ->orderBy('reservations.id')
            ->limit($target)
            ->pluck('reservations.id')
            ->values();

        $rows = [];

        foreach ($reservationIds as $reservationId) {
            $ticketCode = $this->generateTicketCode();
            $payload = json_encode([
                'ticket_code' => $ticketCode,
                'reservation_id' => $reservationId,
                'issued_at' => $now->toIso8601String(),
            ], JSON_THROW_ON_ERROR);

            $rows[] = [
                'reservation_id' => $reservationId,
                'ticket_code' => $ticketCode,
                'qr_code' => base64_encode($payload),
                'issued_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('tickets')->insert($rows);
        }

        $inserted = count($rows);
        $this->command?->info("TicketSeeder inserted {$inserted} rows (target: {$target}).");
    }

    private function generateTicketCode(): string
    {
        do {
            $code = 'TIX-'.Str::upper(Str::random(10));
        } while (DB::table('tickets')->where('ticket_code', $code)->exists());

        return $code;
    }
}
