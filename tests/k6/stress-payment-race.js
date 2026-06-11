/**
 * ============================================================================
 * SKENARIO B — Race Condition Test
 * ============================================================================
 *
 * 2000 user mencoba membayar reservation_id yang SAMA secara bersamaan.
 * Menguji bahwa tidak ada double payment / race condition.
 *
 * Expected: tepat 1 sukses (200), sisanya ditolak (422).
 *
 * Cara menjalankan:
 *   k6 run --vus 2000 --duration 60s tests/k6/stress-payment-race.js
 *   k6 run --vus 100 --duration 30s tests/k6/stress-payment-race.js  (ringan)
 * ============================================================================
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { SharedArray } from "k6/data";
import { Counter } from "k6/metrics";

// Custom metrics — hitung berapa yang sukses vs ditolak vs error
const paymentSuccessCount = new Counter("payment_success_count");
const paymentRejectedCount = new Counter("payment_rejected_count");
const paymentErrorCount = new Counter("payment_error_count");

// Load data user dari CSV
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
const VENUE_ID = 1;
const PASSWORD = "password123";

// Thresholds — tidak boleh ada server error
export const options = {
    thresholds: {
        http_req_failed: ["rate<0.01"],
    },
};

// =============================================================================
// Setup — Login sebagai user pertama, buat 1 reservasi untuk direbut semua VU
// =============================================================================

export function setup() {
    const setupUser = users[0];

    // 1. Login
    const loginRes = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({ email: setupUser.email, password: PASSWORD }),
        { headers: { "Content-Type": "application/json" } }
    );

    if (loginRes.status !== 200) {
        console.error(`SETUP GAGAL: login — status ${loginRes.status}`);
        return { token: null, reservationId: null };
    }

    const jwt = JSON.parse(loginRes.body).data.token;

    // 2. Queue token
    const queueRes = http.post(
        `${BASE_URL}/reservations/queue-token`,
        JSON.stringify({ venue_id: VENUE_ID }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    if (queueRes.status !== 200) {
        console.error(`SETUP GAGAL: queue token — status ${queueRes.status}`);
        return { token: jwt, reservationId: null };
    }

    const queueToken = JSON.parse(queueRes.body).data.token;

    // 3. Hold seat 99999 (khusus race condition, di luar range skenario lain)
    const holdRes = http.post(
        `${BASE_URL}/reservations/hold`,
        JSON.stringify({
            venue_id: VENUE_ID,
            seat_id: 99999,
            queue_token: queueToken,
        }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    if (holdRes.status !== 201) {
        console.error(`SETUP GAGAL: hold seat — status ${holdRes.status}: ${holdRes.body}`);
        return { token: jwt, reservationId: null };
    }

    const holdBody = JSON.parse(holdRes.body);
    const reservationId =
        holdBody.data?.reservation?.id ||
        holdBody.data?.reservation_id ||
        holdBody.data?.id ||
        null;

    console.log(`SETUP OK: user=${setupUser.email}, reservation_id=${reservationId}`);

    return {
        token: jwt,
        reservationId: reservationId,
        ownerEmail: setupUser.email,
    };
}

// =============================================================================
// Main — Setiap VU login sebagai user berbeda, lalu coba bayar reservasi SAMA
// =============================================================================

export default function (data) {
    if (!data.reservationId) {
        console.error("SKIP: reservationId tidak tersedia dari setup");
        paymentErrorCount.add(1);
        return;
    }

    const userIndex = (__VU - 1) % users.length;
    const user = users[userIndex];

    // 1. Login sebagai user masing-masing
    const loginRes = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({ email: user.email, password: PASSWORD }),
        { headers: { "Content-Type": "application/json" } }
    );

    if (loginRes.status !== 200) {
        paymentErrorCount.add(1);
        return;
    }

    const jwt = JSON.parse(loginRes.body).data.token;

    // 2. Coba bayar reservation milik user pertama (dari setup)
    const payRes = http.post(
        `${BASE_URL}/payments/pay`,
        JSON.stringify({ reservation_id: data.reservationId }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    // 3. Kategorikan hasil
    if (payRes.status === 200) {
        // Berhasil bayar — seharusnya HANYA 1 yang bisa
        paymentSuccessCount.add(1);
        console.log(`✓ SUKSES BAYAR: user=${user.email}`);
    } else if (payRes.status === 422) {
        // Ditolak — expected behavior (bukan pemilik reservasi)
        paymentRejectedCount.add(1);
    } else if (payRes.status >= 500) {
        // Server error — TIDAK BOLEH terjadi
        paymentErrorCount.add(1);
        console.error(`✗ SERVER ERROR ${payRes.status}: user=${user.email}`);
    } else {
        // Status lain (401, 403, dll)
        paymentRejectedCount.add(1);
    }

    // 4. Verifikasi — pastikan bukan server error
    check(payRes, {
        "race: bukan server error": (r) => r.status < 500,
        "race: response valid (200/422)": (r) => r.status === 200 || r.status === 422,
    });

    sleep(0.3);
}
