<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvPaths = [
            base_path('tests/k6/users.csv'),
            base_path('tests/k6_dava/users.csv'),
        ];

        $passwordHash = Hash::make('password123');
        $seenEmails = [];

        // Ambil semua email yang sudah terdaftar di database (misalnya test@example.com dari DatabaseSeeder)
        // agar tidak terjadi error unik (Unique Constraint Violation) saat proses insert.
        if (Schema::hasTable('users')) {
            $existingEmails = DB::table('users')->pluck('email')->toArray();
            foreach ($existingEmails as $email) {
                $seenEmails[strtolower(trim($email))] = true;
            }
        }

        $seededAny = false;

        foreach ($csvPaths as $csvPath) {
            if (file_exists($csvPath)) {
                $csvData = file_get_contents($csvPath);
                $lines = explode("\n", $csvData);
                $users = [];

                foreach ($lines as $index => $line) {
                    // Lewati baris header ("email")
                    if ($index === 0) continue;
                    
                    $email = trim(str_replace('"', '', $line));
                    if (empty($email)) continue;

                    // Hindari duplikasi email
                    $normalizedEmail = strtolower($email);
                    if (isset($seenEmails[$normalizedEmail])) {
                        continue;
                    }
                    $seenEmails[$normalizedEmail] = true;

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
                $seededAny = true;
            }
        }

        if (!$seededAny) {
            // Fallback jika file CSV tidak ditemukan
            User::factory()->count(1000)->create([
                'password' => $passwordHash,
            ]);
        }
    }
}
