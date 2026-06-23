<?php

// [OPTIMASI: SCRIPT GENERATOR USER K6]
// Script ini dibuat khusus untuk men-generate data CSV berisi 20.000 email user
// agar stress test k6 bisa mensimulasikan login secara masif.
$file = fopen(__DIR__ . '/tests/k6_dava/users.csv', 'w');
fputcsv($file, ['email']);
for ($i = 1; $i <= 20000; $i++) {
    fputcsv($file, ["user_{$i}@example.com"]);
}
fclose($file);
echo "CSV generated successfully.\n";
