# 📊 Hasil Analisis Stress Test Sistem Reservasi Tiket Konser

## 1. Ringkasan Pengujian

Pengujian dilakukan menggunakan **k6 load testing tool** dengan skenario login, pengambilan queue token, dan proses hold seat pada sistem reservasi tiket konser.

### Parameter Pengujian

| Parameter | Nilai |
|----------|------|
| Virtual Users (VU) | 10 – 30 |
| Durasi Pengujian | ± 40 detik |
| Total Request | 162 |
| Endpoint | Login → Queue Token → Hold Seat |
| Seat Test | Rebutan 1 seat (seat_id = 9999) |

---

## 2. Hasil Kinerja Sistem

### HTTP Performance

| Metric | Value |
|--------|------|
| Average Response Time | 7.32 s |
| Median Response Time | 6.1 s |
| Minimum Response Time | 284 ms |
| Maximum Response Time | 18.29 s |
| p90 | 17.29 s |
| p95 | 17.73 s |
| Throughput | 2.71 req/s |
| HTTP Failure Rate | 33.33% (54/162) |

---

### Iteration Performance (End-to-End Flow)

| Metric | Value |
|--------|------|
| Average Iteration Time | 21.99 s |
| Minimum | 1.31 s |
| Maximum | 33.03 s |
| p95 | 32.89 s |
| Iterations Completed | 54 |

---

## 3. Hasil Login Endpoint (Tambahan Observasi)

Pengujian terpisah pada endpoint login menunjukkan:

| Metric | Value |
|--------|------|
| Response Time | 1.61 s |
| TTFB | 1.60 s |
| Processing Time | ~0.17 ms |

Hal ini menunjukkan bahwa sebagian besar waktu respon dihabiskan pada server-side processing sebelum response dikirim.

---

## 4. Analisis Temuan

### 4.1 Race Condition Handling (Positif)

Sistem berhasil menangani kondisi kompetisi (race condition) pada pemesanan kursi yang sama. Hal ini dibuktikan dengan:

- Sebagian request berhasil (201 Created)
- Sebagian request ditolak (422 Unprocessable Entity)
- Tidak terjadi double booking

**Kesimpulan:**  
Implementasi mekanisme locking / validasi sudah berjalan dengan baik.

---

### 4.2 Bottleneck Performa

Ditemukan beberapa bottleneck utama:

#### a. Latency Tinggi
- Rata-rata response time 7.32 detik tergolong sangat tinggi
- p95 mencapai 17.73 detik menunjukkan sistem tidak stabil pada beban tinggi

#### b. Sequential Flow Berat
- Flow login → queue → hold berjalan secara berurutan (blocking)
- Setiap request memiliki dependency ke proses sebelumnya

#### c. Database & Locking Overhead
- Proses reservasi kemungkinan besar masih bergantung pada database transaction dan locking
- Hal ini menyebabkan peningkatan waktu eksekusi saat concurrency tinggi

---

### 4.3 Throughput Rendah

- Hanya 2.71 request per detik
- Menunjukkan sistem belum optimal untuk skenario high concurrency seperti tiket war

---

### 4.4 Error Rate

- Error rate 33.33% merupakan hasil dari business logic rejection
- Sistem berhasil mencegah double booking pada seat yang sama
- Ini merupakan expected behavior, bukan sistem failure

---

## 5. Kesimpulan

Hasil pengujian menunjukkan bahwa:

1. Sistem **berhasil menangani race condition** dengan baik pada proses pemesanan kursi.
2. Namun, sistem masih mengalami **performa yang lambat**, dengan rata-rata response time di atas 7 detik.
3. Bottleneck utama terdapat pada:
   - Proses authentication
   - Queue token generation
   - Database transaction pada reservasi
4. Throughput sistem masih rendah sehingga belum siap untuk menangani beban tinggi secara real-time.

---

## 6. Rekomendasi Optimasi

Untuk meningkatkan performa sistem, beberapa langkah yang direkomendasikan adalah:

- Implementasi Redis untuk distributed locking pada proses reservasi
- Optimasi query database dengan indexing
- Mengurangi chained request (login → queue → hold)
- Menggunakan caching untuk data yang sering diakses
- Meminimalkan database transaction blocking

---

## 7. Penutup

Secara keseluruhan, sistem sudah stabil dari sisi konsistensi data, namun masih memerlukan optimasi signifikan pada sisi performa untuk dapat digunakan pada skenario real-time ticket booking dengan beban tinggi.
