<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationSeeder extends Seeder
{
    public function run(): void
    {
        /**
         * Run the database seeds.
         */
        $users = DB::table('users')->pluck('id');
        $seats = DB::table('seats')->pluck('id');

        $statuses = ['holding', 'confirmed', 'expired'];

        $data = [];

        for ($i = 0; $i < 1000; $i++) {
            $status = $statuses[array_rand($statuses)];

            $data[] = [
                'user_id' => $users->random(),
                'seat_id' => $seats->random(),
                'status' => $status,
                'expired_at' => $status === 'holding'
                    ? Carbon::now()->addMinutes(rand(5, 10))
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('reservations')->insert($data);
    }
}
