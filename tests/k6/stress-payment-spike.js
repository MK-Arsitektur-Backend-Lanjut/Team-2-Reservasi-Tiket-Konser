/**
 * ============================================================================
 * SKENARIO A — Payment Under Spike Load (Spike Test)
 * ============================================================================
 *
 * Simulasi ticket war: user login → hold seat → bayar secara bersamaan.
 * Menguji performa endpoint payment yang dioptimasi Redis.
 *
 * Cara menjalankan:
 *   k6 run --vus 2000 --duration 60s tests/k6/stress-payment-spike.js
 *   k6 run --vus 100 --duration 30s tests/k6/stress-payment-spike.js  (ringan)
 * ============================================================================
 */

import http from "k6/http";
import { check, sleep, group } from "k6";
import { SharedArray } from "k6/data";
import { Trend } from "k6/metrics";

// Custom metrics
const paymentDuration = new Trend("payment_duration", true);
const holdDuration = new Trend("hold_duration", true);

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

// Thresholds
export const options = {
    thresholds: {
        http_req_duration: ["p(95)<2000"],
        http_req_failed: ["rate<0.05"],
        payment_duration: ["p(95)<500"],
    },
};

// =============================================================================
// Helper Functions
// =============================================================================

function login(email) {
    const res = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({ email, password: PASSWORD }),
        { headers: { "Content-Type": "application/json" } }
    );

    if (res.status !== 200) {
        console.error(`LOGIN GAGAL: ${email} — status ${res.status}`);
        return null;
    }

    return JSON.parse(res.body).data.token;
}

function getQueueToken(jwt, venueId) {
    const res = http.post(
        `${BASE_URL}/reservations/queue-token`,
        JSON.stringify({ venue_id: venueId }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    if (res.status !== 200) {
        console.error(`QUEUE TOKEN GAGAL — status ${res.status}`);
        return null;
    }

    return JSON.parse(res.body).data.token;
}

function holdSeat(jwt, venueId, seatId, queueToken) {
    const res = http.post(
        `${BASE_URL}/reservations/hold`,
        JSON.stringify({
            venue_id: venueId,
            seat_id: seatId,
            queue_token: queueToken,
        }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    holdDuration.add(res.timings.duration);

    if (res.status !== 201) {
        console.error(`HOLD GAGAL seat=${seatId} — status ${res.status}`);
        return null;
    }

    return JSON.parse(res.body);
}

function pay(jwt, reservationId) {
    const res = http.post(
        `${BASE_URL}/payments/pay`,
        JSON.stringify({ reservation_id: reservationId }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    return {
        response: res,
        body: res.body ? JSON.parse(res.body) : null,
        duration: res.timings.duration,
    };
}

// =============================================================================
// Main — Setiap VU: Login → Queue → Hold → Pay
// =============================================================================

export default function () {
    const userIndex = (__VU - 1) % users.length;
    const user = users[userIndex];

    // 1. Login
    const jwt = login(user.email);
    if (!jwt) return;

    // 2. Queue token
    const queueToken = getQueueToken(jwt, VENUE_ID);
    if (!queueToken) return;

    // 3. Hold seat — seat_id unik per VU+iteration (range 1-50000)
    const seatId = ((__VU - 1) * 10 + __ITER) % 50000 + 1;

    const holdResult = holdSeat(jwt, VENUE_ID, seatId, queueToken);
    if (!holdResult) return;

    // Ambil reservation_id dari response
    const reservationId =
        holdResult.data?.reservation?.id ||
        holdResult.data?.reservation_id ||
        holdResult.data?.id;

    if (!reservationId) {
        console.error("RESERVATION ID TIDAK DITEMUKAN di response hold");
        return;
    }

    // 4. Pay — endpoint yang dioptimasi Redis
    const payResult = pay(jwt, reservationId);
    paymentDuration.add(payResult.duration);

    // 5. Verifikasi
    check(payResult.response, {
        "payment: status 200": (r) => r.status === 200,
        "payment: ada ticket": () =>
            payResult.body?.data?.ticket !== undefined &&
            payResult.body?.data?.ticket !== null,
        "payment: response < 500ms": () => payResult.duration < 500,
    });

    sleep(0.5);
}
