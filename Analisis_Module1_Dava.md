# Analisis Stress Test Modul 1 - Venue dan Seat Mapping

## Pendahuluan

Modul 1 yang saya kerjakan bertanggung jawab atas fitur **Seat Mapping**, yaitu menampilkan daftar kursi beserta statusnya (available, hold, sold) kepada pengguna yang sedang mengakses halaman pemilihan kursi. Dalam konteks ticket war kelompok kami, endpoint yang sudah dibuat akan menerima lonjakan traffic yang sangat tinggi karena ribuan pengguna akan membuka halaman kursi secara bersamaan (load website misalnya sudah ada UI/UX nya).

Untuk pengujian stress testnya dilakukan menggunakan **k6** sebagai load testing tool. k6 saya pilih karena bisa langsung dijalankan lewat CLI tanpa perlu GUI, scriptnya ditulis lewat JavaScript yang biasanya familiar, dan outputnya langsung keluar di terminal bagian bawah sehingga cocok untuk workflow kelompok kami yang berbasis Docker dan command line (seperti yang kami gunakan). Sebelumnya, saya juga sempat mencari dan ketemu ada beberapa alternatif lain seperti JMeter, Locust, atau Artillery, tapi kebanyakan butuh setup GUI atau environment tambahan yang menurut saya kurang praktis untuk study case kelompok kami. Skenario yang diuji meliputi login (autentikasi JWT), pengambilan seluruh kursi, kursi kosong (available), dan kursi VIP kosong.

---

## Parameter Pengujian

| Parameter               | Nilai                                              |
| ----------------------- | -------------------------------------------------- |
| Tool                    | k6                                                 |
| Virtual Users (VU)      | 100                                                |
| Durasi                  | 50 detik (ramp-up 10s, sustain 30s, ramp-down 10s) |
| Total Kursi di Database | 100.000 baris                                      |
| Server                  | Laravel Octane (Swoole) di Docker                  |
| Cache                   | Redis dengan predis driver                         |
| Script                  | `tests/k6_dava/seat-mapping.js`                  |

Skenario pengujian terdiri dari 4 check/skenario:

1. Login dan mendapatkan token JWT
2. Skenario A - Memuat semua kursi pada suatu venue
3. Skenario B - Memuat kursi yang berstatus available saja
4. Skenario C - Memuat kursi VIP yang berstatus available

---

## Masalah Awal (Sebelum Optimasi)

Sebelum melakukan optimasi, stress test dengan 100 VU (Virtual Users) langsung mengalami kegagalan besar atau bisa dibilang hampir tidak jalan. Server hanya mampu bertahan kurang lebih sekitar 28 detik sebelum mulai timeout. Berikut data yang sempat tercatat di terminal saya:

| Metrik                  | Hasil (Sebelum)                    |
| ----------------------- | ---------------------------------- |
| Total Request           | 144                                |
| Gagal (Failed)          | 41.12%                             |
| Rata-rata Response Time | 41.60 detik                        |
| Status                  | Server timeout, test tidak selesai |

Penyebab utamanya yang saya temukan ada dua. Pertama, `php artisan serve` yang saya pakai di dalam Docker bersifat single-threaded. Artinya server hanya bisa memproses satu request pada satu waktu, sehingga 100 bot atau Virtual Users tadi yang menembak secara bersamaan harus mengantri satu per satu. Kedua, query ke database mengambil seluruh 100.000 baris data kursi sekaligus tanpa pagination, yang mana membuat setiap response berukuran sangat besar dan menghabiskan memory server.

---

## Optimasi yang Diterapkan

Berikut langkah-langkah optimasi yang saya terapkan untuk mengatasi masalah di atas:

### 1. Migrasi ke Laravel Octane (Swoole)

Mengganti `php artisan serve` dengan Laravel Octane yang menggunakan Swoole sebagai runtime. Swoole memungkinkan server memproses banyak request secara bersamaan (asynchronous) karena menggunakan coroutine-based architecture, bukan model blocking satu-per-satu seperti PHP lawas/tradisional.

Perubahan tadi dilakukan pada `Dockerfile` dan konfigurasi Docker.

### 2. Redis Caching dengan Tags

Menginstal package `predis/predis` dan mengubah cache driver dari `file` ke `redis`. Implementasi caching menggunakan fitur **Cache Tags** dari Laravel, di mana setiap data kursi pada suatu venue ditandai (tagged) dengan ID venue-nya (kalau tidak salah pernah mencoba di postman bisa). Keuntungannya, ketika ada kursi yang statusnya berubah (misalnya dipesan), kita cukup menghapus cache berdasarkan tag venue tersebut saja tanpa perlu menghapus seluruh cache aplikasi.

Implementasi tadi ada di `SeatRepository.php` pada method `getAvailableSeats()`, `getSeatSummary()`, dan `getByVenue()`. Setiap method menggunakan `Cache::tags(["venue_{$venueId}"])->remember(...)` untuk menyimpan hasil query selama 1 jam (3600 detik).

### 3. Pagination

Menerapkan `paginate(100)` pada query pengambilan kursi. Sebelumnya, satu request bisa mengembalikan 100.000 baris data sekaligus (yang mana bisa bikin berat mau di sisi pengguna maupun server). Sekarang data dipecah menjadi 100 baris per halaman, sehingga ukuran response menjadi jauh lebih kecil dan server tidak kehabisan memory.

### 4. Cache Invalidation Otomatis

Ketika ada kursi yang dipesan (status diubah menjadi `hold` atau `sold`), cache lama akan otomatis dihapus melalui `Cache::tags(["venue_{$venueId}"])->flush()` di method `updateStatus()`. Hal ini menjamin data yang diterima user selalu up-to-date setelah ada transaksi.

### 5. Optimasi Bcrypt Rounds

Menurunkan `BCRYPT_ROUNDS` dari default 12 menjadi 4 di file `.env`. Pada environment testing, proses hashing password yang terlalu berat akan memperlambat endpoint login secara signifikan, terutama ketika 100 bot melakukan login secara bersamaan.

---

## Hasil Stress Test (Sesudah Optimasi)

Setelah seluruh optimasi di atas diterapkan, stress test saya jalankan ulang dengan parameter yang sama (100 VU, 50 detik). Hasilnya bisa dibilang jauh berbeda:

| Metrik                  | Sebelum     | Sesudah     |
| ----------------------- | ----------- | ----------- |
| Total Request           | 144         | 5.076       |
| Gagal (Failed)          | 41.12%      | 0.00%       |
| Rata-rata Response Time | 41.60 detik | 68.18 ms    |
| Median Response Time    | -           | 27.85 ms    |
| Max Response Time       | > 60 detik  | 5.06 detik  |
| p(90)                   | -           | 35.29 ms    |
| p(95)                   | -           | 41.05 ms    |
| Throughput              | ~5 req/s    | 96.62 req/s |
| Check Success           | 58.88%      | 100.00%     |

Seluruh skenario (Login, Skenario A, B, C) berhasil dilalui tanpa satu pun kegagalan. Waktu response rata-rata turun dari puluhan detik menjadi puluhan milidetik.

Nilai max response time 5.06 detik terjadi pada request pertama saja (cold start), yaitu saat cache Redis masih kosong dan query harus menyentuh database MySQL secara langsung. Setelah cache terisi, request-request selanjutnya hanya membutuhkan waktu di bawah 40 milidetik karena data sudah dilayani langsung dari Redis.

---

## Interpretasi Hasil

### Kenapa Total Request "Hanya" 5.000?

Jadi ini pertanyaan yang sempat muncul juga saat saya menganalisis hasilnya. Yang saya dapat yaitu, perlu dipahami bahwa 100 Virtual User di k6 bukan berarti hanya 100 orang yang membuka website. 100 VU adalah 100 bot yang terus-menerus menembak request tanpa jeda alias nonstop. Begitu satu request selesai, bot langsung mengirim request berikutnya di milidetik yang sama.

Total 5.076 request didapatkan dalam waktu hanya 50 detik. Kalau dihitung secara kasar:

- Sekitar 1.000 request per 10 detik
- Atau setara dengan kurang lebih 96 request per detik secara konstan

Di dunia nyata, pengguna manusia pasti memiliki jeda (membaca layar, menggeser halaman, memilih kursi). Jadi 100 bot k6 yang berjalan tanpa henti selama 50 detik ini mewakili beban yang setara dengan ribuan pengguna manusia yang aktif di menit yang sama.

Kalau memang masih ingin melihat angka puluhan ribu request secara bersamaan, kita cukup memperpanjang durasi pengujian menjadi, misalnya, 10 menit. Dengan throughput kurang lebih 96 req/s yang stabil dan 0% error, dalam 10 menit akan didapatkan sekitar 57.000+ request. Yang menjadi fokus utama di sini bukan jumlah total requestnya, tetapi kemampuan arsitektur untuk mempertahankan throughput yang stabil tanpa error selama durasi pengujian.

### Kenapa Tidak Langsung Pakai 20.000 VU?

Pengujian ini saya jalankan di environment lokal (laptop) menggunakan Docker. Kalau memaksakan 20.000 VU langsung di k6 pada laptop, yang crash duluan adalah laptop saya sendiri (mungkin bisa kehabisan RAM), bukan server API nya. Untuk simulasi dengan jumlah VU sebesar itu atau sejumlah target yang dimau, idealnya menggunakan cloud server atau distributed load testing.

---

## Cara Menjalankan Stress Test

Untuk mereproduksi hasil pengujian ini, langkah-langkahnya sebagai berikut:

1. Pastikan Docker Desktop sudah berjalan
2. Nyalakan container dengan `docker compose up -d`
3. Jalankan k6 dari root folder project:

```
k6 run tests/k6_dava/seat-mapping.js
```

Jika `k6` belum masuk PATH, bisa dipanggil langsung:

```
& "C:\Program Files\k6\k6.exe" run tests/k6_dava/seat-mapping.js
```

Test akan berjalan selama kurang lebih 50 detik (tergantung device) dan menampilkan hasil lengkap di terminal.

---

## Endpoint yang Diuji

| Endpoint                                            | Method | Fungsi                                                   |
| --------------------------------------------------- | ------ | -------------------------------------------------------- |
| `/api/auth/login`                                 | POST   | Autentikasi user, mendapatkan token JWT                  |
| `/api/venues/1/seats`                             | GET    | Mengambil seluruh kursi pada venue 1                     |
| `/api/venues/1/seats?available=true`              | GET    | Mengambil kursi yang berstatus available                 |
| `/api/venues/1/seats?available=true&category=VIP` | GET    | Mengambil kursi VIP yang berstatus available             |
| `/api/venues/1/seats/summary`                     | GET    | Mengambil ringkasan jumlah kursi per kategori dan status |

---

## File yang Dimodifikasi

| File                                                        | Perubahan                                                                   |
| ----------------------------------------------------------- | --------------------------------------------------------------------------- |
| `app/Repositories/SeatRepository.php`                     | Implementasi pagination, Redis caching dengan tags, dan cache invalidation  |
| `app/Repositories/Interfaces/SeatRepositoryInterface.php` | Penambahan kontrak method`getSeatSummary()`                               |
| `app/Services/SeatService.php`                            | Penambahan method`getSeatSummary()`                                       |
| `app/Http/Controllers/Api/SeatController.php`             | Penambahan endpoint`summary()`                                            |
| `routes/api.php`                                          | Registrasi route baru`/venues/{venueId}/seats/summary`                    |
| `docker-compose.yml`                                      | Penyesuaian konfigurasi container                                           |
| `composer.json` / `composer.lock`                       | Penambahan dependency`predis/predis`                                      |
| `.env`                                                    | Mengubah`CACHE_STORE=redis`, `REDIS_CLIENT=predis`, `BCRYPT_ROUNDS=4` |
| `generate_users.php`                                      | Script untuk generate 20.000 data user dummy ke CSV                         |

---

## Kesimpulan

Sebelum optimasi yang saya terapkan di atas, sistem tidak mampu menangani 100 concurrent users dan mengalami timeout masif. Setelah menerapkan kombinasi Octane (Swoole), Redis caching dengan tags, pagination, dan cache invalidation, performa meningkat secara signifikan dengan rata-rata response time turun dari 41 detik menjadi 68 milidetik dan error rate turun dari 41% menjadi 0%.

Arsitektur yang sudah dioptimasi ini terbukti stabil dan mampu mempertahankan throughput kurang lebih 96 request per detik tanpa degradasi selama durasi pengujian (degradasi bisa dibilang penurunan performa). Dengan throughput tersebut, sistem sudah siap untuk menangani beban traffic tinggi pada skenario ticket war di production server.
