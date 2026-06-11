<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$users = \App\Models\User::pluck('email');
$csv = "email\n";
foreach ($users as $u) {
    $csv .= "\"{$u}\"\n";
}
file_put_contents(__DIR__.'/tests/k6/users.csv', $csv);
echo "Berhasil export " . $users->count() . " user ke tests/k6/users.csv\n";
