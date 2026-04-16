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
        $totalSeats = 10000;
        
        for ($i = 1; $i <= $totalSeats; $i++) {
            if ($i <= 1000) {
                $category = 'VIP';
                $price = 2500000;
            } elseif ($i <= 4000) {
                $category = 'Regular';
                $price = 1000000;
            } else {
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
