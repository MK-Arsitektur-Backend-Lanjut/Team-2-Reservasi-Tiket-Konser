import http from "k6/http";
import { check, sleep } from "k6";
import { SharedArray } from "k6/data";

// Memuat data email uji dari file CSV lokal untuk pengujian
const users = new SharedArray("users", function () {
    const csv = open("./users.csv");

    return csv
        .split("\n")
        .slice(1)
        .filter((line) => line.trim() !== "")
        .map((line) => ({
            email: line.replace(/"/g, "").trim(),
        }));
});

const BASE_URL = __ENV.BASE_URL || "http://127.0.0.1:8000/api";

// Konfigurasi Pengujian Performa (Spike & Stress Testing)
export const options = {
    // Tahapan beban untuk menyimulasikan lonjakan trafik mendadak (Spike Test) saat Ticket War
    stages: [
        { duration: "10s", target: 100 }, // Naikkan pengguna menjadi 100 secara bertahap dalam 10 detik
        { duration: "30s", target: 100 }, // Pertahankan beban 100 pengguna selama 30 detik (Stress Test)
        { duration: "10s", target: 0 },   // Turunkan pengguna kembali ke 0 secara bertahap
    ],
};

export default function () {
    // Mengambil user uji secara dinamis berdasarkan ID Virtual User (VU) k6
    const user = users[(__VU - 1) % users.length];

    // 1. LOGIN
    // Dibutuhkan untuk mendapatkan JWT/Sanctum Token karena route API kursi dilindungi middleware auth:sanctum
    const loginRes = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({
            email: user.email,
            password: "password123", // Password default dari seeder
        }),
        {
            headers: {
                "Content-Type": "application/json",
            },
        },
    );

    const loginOk = check(loginRes, {
        "1. Login sukses (JWT didapatkan)": (r) => r.status === 200,
    });

    if (!loginOk) {
        console.log(`[LOGIN GAGAL] User: ${user.email}`);
        return;
    }

    const jwt = JSON.parse(loginRes.body).data.token;
    const authHeaders = {
        Authorization: `Bearer ${jwt}`,
        "Content-Type": "application/json",
    };

    // ==========================================
    // Skenario Pengujian Modul 1: Venue & Seat Mapping
    // ==========================================

    // Skenario A: Query semua data kursi di Venue 1 (Total 100.000 kursi)
    // Skenario ini menghasilkan payload JSON yang sangat besar. Menguji kapasitas throughput jaringan dan parse JSON server.
    const allSeatsRes = http.get(`${BASE_URL}/venues/1/seats`, { headers: authHeaders });
    check(allSeatsRes, {
        "Skenario A - Sukses memuat semua kursi": (r) => r.status === 200,
    });

    sleep(1); // Jeda 1 detik simulasi user membaca peta kursi sebelum filter

    // Skenario B: Query hanya kursi yang available (Memanfaatkan index idx_seats_venue_status_category)
    // Query ini harus berjalan sangat cepat karena database menyaring data dengan indeks gabungan yang telah dioptimasi.
    const availableSeatsRes = http.get(`${BASE_URL}/venues/1/seats?available=true`, { headers: authHeaders });
    check(availableSeatsRes, {
        "Skenario B - Sukses memuat kursi kosong": (r) => r.status === 200,
    });

    sleep(1);

    // Skenario C: Query kursi yang available dan bertipe VIP (Memanfaatkan index secara penuh)
    // Memastikan pencarian kategori khusus tetap responsif di bawah tekanan trafik tinggi.
    const vipSeatsRes = http.get(`${BASE_URL}/venues/1/seats?available=true&category=VIP`, { headers: authHeaders });
    check(vipSeatsRes, {
        "Skenario C - Sukses memuat kursi VIP kosong": (r) => r.status === 200,
    });

    sleep(1);
}
