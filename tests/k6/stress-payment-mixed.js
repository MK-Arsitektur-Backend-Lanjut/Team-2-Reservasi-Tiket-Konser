/**
 * ============================================================================
 * SKENARIO C — Mixed Read/Write Workload (Soak Test)
 * ============================================================================
 *
 * Beban campuran: 70% read (cek reservasi), 30% write (payment baru).
 * Menguji stabilitas Redis cache dan write-through consistency
 * selama durasi panjang.
 *
 * Cara menjalankan:
 *   k6 run --vus 2000 --duration 180s tests/k6/stress-payment-mixed.js
 *   k6 run --vus 100 --duration 60s tests/k6/stress-payment-mixed.js  (ringan)
 * ============================================================================
 */

import http from "k6/http";
import { check, sleep } from "k6";
import { SharedArray } from "k6/data";
import { Trend, Rate } from "k6/metrics";

// Custom metrics
const readDuration = new Trend("read_duration", true);
const writeDuration = new Trend("write_duration", true);
const errorRate = new Rate("error_rate");

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
        http_req_duration: ["p(95)<1500"],
        http_req_failed: ["rate<0.02"],
        read_duration: ["p(95)<300"],
        write_duration: ["p(95)<1000"],
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

    if (res.status !== 200) return null;
    return JSON.parse(res.body).data.token;
}

function getQueueToken(jwt) {
    const res = http.post(
        `${BASE_URL}/reservations/queue-token`,
        JSON.stringify({ venue_id: VENUE_ID }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        }
    );

    if (res.status !== 200) return null;
    return JSON.parse(res.body).data.token;
}

// =============================================================================
// Main — 70% read, 30% write
// =============================================================================

export default function () {
    const userIndex = (__VU - 1) % users.length;
    const user = users[userIndex];

    // Login
    const jwt = login(user.email);
    if (!jwt) {
        errorRate.add(1);
        sleep(1);
        return;
    }

    // Random: 70% read, 30% write
    const roll = Math.random();

    if (roll < 0.7) {
        // =================================================================
        // READ — GET daftar reservasi + detail (menguji Redis cache read)
        // =================================================================
        const startRead = Date.now();

        // GET daftar reservasi
        const listRes = http.get(`${BASE_URL}/reservations`, {
            headers: { Authorization: `Bearer ${jwt}` },
        });

        const listOk = check(listRes, {
            "read-list: status 200": (r) => r.status === 200,
            "read-list: bukan 500": (r) => r.status < 500,
        });

        errorRate.add(listRes.status >= 500 ? 1 : 0);

        // Jika ada reservasi, ambil detail yang pertama
        if (listOk && listRes.status === 200) {
            try {
                const body = JSON.parse(listRes.body);
                const reservations = body.data?.reservations || body.data || [];

                if (Array.isArray(reservations) && reservations.length > 0) {
                    const randomRes =
                        reservations[Math.floor(Math.random() * reservations.length)];
                    const resId = randomRes.id || randomRes.reservation_id;

                    if (resId) {
                        const detailRes = http.get(
                            `${BASE_URL}/reservations/${resId}`,
                            { headers: { Authorization: `Bearer ${jwt}` } }
                        );

                        check(detailRes, {
                            "read-detail: status 200": (r) => r.status === 200,
                            "read-detail: bukan 500": (r) => r.status < 500,
                        });

                        errorRate.add(detailRes.status >= 500 ? 1 : 0);
                    }
                }
            } catch (e) {
                // Parse error — abaikan
            }
        }

        readDuration.add(Date.now() - startRead);
    } else {
        // =================================================================
        // WRITE — Queue → Hold seat baru → Pay (menguji write-through cache)
        // =================================================================
        const startWrite = Date.now();

        // Queue token
        const queueToken = getQueueToken(jwt);
        if (!queueToken) {
            errorRate.add(1);
            writeDuration.add(Date.now() - startWrite);
            sleep(1);
            return;
        }

        // Hold seat — range 50001-99998 (terpisah dari skenario A)
        const seatId = ((__VU - 1) * 10 + __ITER) % 49998 + 50001;

        const holdRes = http.post(
            `${BASE_URL}/reservations/hold`,
            JSON.stringify({
                venue_id: VENUE_ID,
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

        if (holdRes.status !== 201) {
            // Seat mungkin sudah diambil — wajar
            check(holdRes, {
                "write-hold: bukan 500": (r) => r.status < 500,
            });
            errorRate.add(holdRes.status >= 500 ? 1 : 0);
            writeDuration.add(Date.now() - startWrite);
            sleep(1);
            return;
        }

        // Ambil reservation_id
        const holdBody = JSON.parse(holdRes.body);
        const reservationId =
            holdBody.data?.reservation?.id ||
            holdBody.data?.reservation_id ||
            holdBody.data?.id;

        if (!reservationId) {
            errorRate.add(1);
            writeDuration.add(Date.now() - startWrite);
            sleep(1);
            return;
        }

        // Pay — menguji Redis write-through
        const payRes = http.post(
            `${BASE_URL}/payments/pay`,
            JSON.stringify({ reservation_id: reservationId }),
            {
                headers: {
                    Authorization: `Bearer ${jwt}`,
                    "Content-Type": "application/json",
                },
            }
        );

        check(payRes, {
            "write-pay: status 200 atau 422": (r) =>
                r.status === 200 || r.status === 422,
            "write-pay: bukan 500": (r) => r.status < 500,
        });

        errorRate.add(payRes.status >= 500 ? 1 : 0);
        writeDuration.add(Date.now() - startWrite);
    }

    sleep(1); // Think time
}
