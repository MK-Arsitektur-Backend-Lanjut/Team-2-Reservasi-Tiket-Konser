<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = 'aabbott@example.com';
$u = \App\Models\User::where('email', $email)->first();
if (!$u) {
    echo "NOT FOUND\n";
    exit;
}
echo "FOUND\n";
echo "Password hash: " . $u->password . "\n";
echo "Check 'password': " . (\Illuminate\Support\Facades\Hash::check('password', $u->password) ? 'YES' : 'NO') . "\n";
echo "Check 'password123': " . (\Illuminate\Support\Facades\Hash::check('password123', $u->password) ? 'YES' : 'NO') . "\n";
