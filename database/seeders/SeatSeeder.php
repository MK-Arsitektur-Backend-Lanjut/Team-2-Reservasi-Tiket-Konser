<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\Venue;
use Illuminate\Support\Facades\DB;

class SeatSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $venue = Venue::first();
        if (!$venue) return;

        $seats = [];
        // Diubah menjadi 100.000 kursi untuk menguji kesiapan sistem (stress test)
        // menghadapi venue skala besar seperti Stadion Utama Gelora Bung Karno (GBK).
        $totalSeats = 100000;
        
        for ($i = 1; $i <= $totalSeats; $i++) {
            // Proporsi pembagian kursi (10% VIP, 30% Regular, 60% Festival)
            if ($i <= 10000) { // 10.000 kursi pertama
                $category = 'VIP';
                $price = 2500000;
            } elseif ($i <= 40000) { // 30.000 kursi berikutnya
                $category = 'Regular';
                $price = 1000000;
            } else { // 60.000 sisanya
                $category = 'Festival';
                $price = 500000;
            }

            $seats[] = [
                'venue_id' => $venue->id,
                'seat_number' => $category . '-' . $i,
                'category' => $category,
                'price' => $price,
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            // Batch insert per 1.000 data agar tidak melebihi batas memori PHP & performa database tetap terjaga
            if (count($seats) == 1000) {
                DB::table('seats')->insert($seats);
                $seats = [];
            }
        }
        
        if (count($seats) > 0) {
            DB::table('seats')->insert($seats);
        }
    }
}
