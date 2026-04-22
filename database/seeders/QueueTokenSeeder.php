<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class QueueTokenSeeder extends Seeder
{
    public function run(): void
    {
        /**
         * Run the database seeds.
         */
        $users = DB::table('users')->pluck('id');
        $venues = DB::table('venues')->pluck('id');

        $data = [];

        for ($i = 0; $i < 1000; $i++) {
            $expiredAt = Carbon::now()->addMinutes(rand(5, 10));

            $data[] = [
                'user_id' => $users->random(),
                'venue_id' => $venues->random(),
                'token' => Str::uuid(),
                'used_at' => rand(0, 1) ? Carbon::now() : null,
                'expired_at' => $expiredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('queue_tokens')->insert($data);
    }
}
