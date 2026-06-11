<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPath = base_path('tests/k6/users.csv');

        // Mengimpor user langsung dari file CSV agar data user di database sinkron dengan k6 stress test
        if (file_exists($csvPath)) {
            $csvData = file_get_contents($csvPath);
            $lines = explode("\n", $csvData);
            
            // Definisikan hash password sekali di luar loop agar seeder berjalan cepat (tidak menghash 1000x)
            $passwordHash = Hash::make('password123');

            $users = [];
            $seenEmails = [];

            // Ambil semua email yang sudah terdaftar di database (misalnya test@example.com dari DatabaseSeeder)
            // agar tidak terjadi error unik (Unique Constraint Violation) saat proses insert.
            $existingEmails = DB::table('users')->pluck('email')->toArray();
            foreach ($existingEmails as $email) {
                $seenEmails[strtolower(trim($email))] = true;
            }

            foreach ($lines as $index => $line) {
                // Lewati baris header ("email")
                if ($index === 0) continue;
                
                $email = trim(str_replace('"', '', $line));
                if (empty($email)) continue;

                // Hindari duplikasi email yang mungkin ada di dalam CSV
                if (isset($seenEmails[$email])) {
                    continue;
                }
                $seenEmails[$email] = true;

                // Ambil nama dari bagian sebelum karakter '@'
                $name = explode('@', $email)[0];
                $name = ucwords(str_replace('.', ' ', $name));

                $users[] = [
                    'name' => $name,
                    'email' => $email,
                    'password' => $passwordHash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                // Batch insert per 100 data untuk menjaga performa seeder
                if (count($users) === 100) {
                    DB::table('users')->insert($users);
                    $users = [];
                }
            }

            if (count($users) > 0) {
                DB::table('users')->insert($users);
            }
        } else {
            // Fallback jika file CSV tidak ditemukan
            User::factory()->count(1000)->create([
                'password' => Hash::make('password123'),
            ]);
        }
    }
}
