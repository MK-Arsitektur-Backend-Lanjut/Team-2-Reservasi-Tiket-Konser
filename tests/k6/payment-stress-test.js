/**
 * ============================================================================
 * STRESS TEST PAYMENT — Reservasi Tiket Konser
 * ============================================================================
 *
 * Script k6 untuk menguji performa modul Payment yang sudah dioptimasi
 * dengan Redis caching/indexing layer.
 *
 * 3 Skenario:
 *   A) Spike Test      — 2000 VU login → hold → pay secara bersamaan
 *   B) Race Condition  — 2000 VU bayar reservation_id yang sama
 *   C) Mixed Workload  — 70% read, 30% write selama 3 menit
 *
 * Cara menjalankan:
 *   # Semua skenario sekaligus
 *   k6 run payment-stress-test.js
 *
 *   # Skenario tertentu saja (k6 v2.x menggunakan --only)
 *   k6 run --only spike_payment payment-stress-test.js
 *   k6 run --only race_condition payment-stress-test.js
 *   k6 run --only mixed_workload payment-stress-test.js
 *
 *   # Dengan custom base URL
 *   k6 run -e BASE_URL=http://192.168.1.10:8000/api payment-stress-test.js
 * ============================================================================
 */

import http from "k6/http";
import { check, sleep, group } from "k6";
import { SharedArray } from "k6/data";
import { Trend, Counter, Rate } from "k6/metrics";

// =============================================================================
// Custom Metrics
// =============================================================================

// Skenario A — Spike Test
const paymentDuration = new Trend("payment_duration", true);
const holdDuration = new Trend("hold_duration", true);

// Skenario B — Race Condition
const paymentSuccessCount = new Counter("payment_success_count");
const paymentRejectedCount = new Counter("payment_rejected_count");
const paymentErrorCount = new Counter("payment_error_count");

// Skenario C — Mixed Workload
const readDuration = new Trend("read_duration", true);
const writeDuration = new Trend("write_duration", true);
const errorRate = new Rate("error_rate");

// =============================================================================
// Data & Konfigurasi
// =============================================================================

const users = new SharedArray("users", function () {
    const csv = open("./users.csv");
    return csv
        .split("\n")
        .slice(1) // skip header
        .filter((line) => line.trim() !== "")
        .map((line) => ({
            email: line.replace(/"/g, "").trim(),
        }));
});

const BASE_URL = __ENV.BASE_URL || "http://127.0.0.1:8000/api";
const VENUE_ID = 1;
const PASSWORD = "password123";

// =============================================================================
// k6 Options — 3 Skenario
// =============================================================================

export const options = {
    scenarios: {
        // =====================================================================
        // Skenario A: Payment Under Spike Load
        // Simulasi ticket war — 2000 user ramp-up drastis, hold seat, bayar
        // =====================================================================
        spike_payment: {
            executor: "ramping-vus",
            startVUs: 0,
            stages: [
                { duration: "15s", target: 2000 }, // Ramp-up drastis 0 → 2000
                { duration: "60s", target: 2000 }, // Sustained spike 60 detik
                { duration: "10s", target: 0 },    // Ramp-down
            ],
            tags: { scenario: "spike" },
            exec: "spikePayment",
        },

        // =====================================================================
        // Skenario B: Concurrent Payment pada Reservasi yang Sama
        // 2000 user coba bayar 1 reservation — hanya 1 yang boleh berhasil
        // =====================================================================
        race_condition: {
            executor: "shared-iterations",
            vus: 2000,
            iterations: 2000,
            maxDuration: "60s",
            tags: { scenario: "race_condition" },
            exec: "raceCondition",
        },

        // =====================================================================
        // Skenario C: Mixed Read/Write Workload (Soak Test)
        // 70% read (cek reservasi/payment), 30% write (payment baru)
        // Menguji stabilitas Redis cache selama durasi panjang
        // =====================================================================
        mixed_workload: {
            executor: "constant-vus",
            vus: 2000,
            duration: "3m",
            tags: { scenario: "mixed" },
            exec: "mixedWorkload",
        },
    },

    // Thresholds global + per skenario
    thresholds: {
        // Skenario A
        "http_req_duration{scenario:spike}": ["p(95)<2000"],
        "http_req_failed{scenario:spike}": ["rate<0.05"],
        "payment_duration": ["p(95)<500"],

        // Skenario B — tidak ada threshold ketat, fokus pada counting
        "http_req_failed{scenario:race_condition}": ["rate<0.01"],

        // Skenario C
        "http_req_duration{scenario:mixed}": ["p(95)<1500"],
        "http_req_failed{scenario:mixed}": ["rate<0.02"],
        "read_duration": ["p(95)<300"],
        "write_duration": ["p(95)<1000"],
    },
};

// =============================================================================
// Helper Functions — Reusable untuk semua skenario
// =============================================================================

/**
 * Login user dan return JWT token.
 * @param {string} email - Email user
 * @returns {string|null} JWT token atau null jika gagal
 */
function login(email) {
    const res = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({ email: email, password: PASSWORD }),
        {
            headers: { "Content-Type": "application/json" },
            tags: { name: "POST /auth/login" },
        }
    );

    const ok = check(res, {
        "login: status 200": (r) => r.status === 200,
    });

    if (!ok) {
        console.error(`LOGIN GAGAL: ${email} — status ${res.status}`);
        return null;
    }

    return JSON.parse(res.body).data.token;
}

/**
 * Request queue token untuk venue tertentu.
 * @param {string} jwt - JWT token
 * @param {number} venueId - ID venue
 * @returns {string|null} Queue token atau null jika gagal
 */
function getQueueToken(jwt, venueId) {
    const res = http.post(
        `${BASE_URL}/reservations/queue-token`,
        JSON.stringify({ venue_id: venueId }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
            tags: { name: "POST /reservations/queue-token" },
        }
    );

    const ok = check(res, {
        "queue-token: status 200": (r) => r.status === 200,
    });

    if (!ok) {
        console.error(`QUEUE TOKEN GAGAL — status ${res.status}`);
        return null;
    }

    return JSON.parse(res.body).data.token;
}

/**
 * Hold seat (reservasi kursi).
 * @param {string} jwt - JWT token
 * @param {number} venueId - ID venue
 * @param {number} seatId - ID kursi
 * @param {string} queueToken - Queue token
 * @returns {object|null} Response body parsed atau null jika gagal
 */
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
            tags: { name: "POST /reservations/hold" },
        }
    );

    holdDuration.add(res.timings.duration);

    const ok = check(res, {
        "hold: status 201": (r) => r.status === 201,
    });

    if (!ok) {
        console.error(`HOLD GAGAL seat=${seatId} — status ${res.status}: ${res.body}`);
        return null;
    }

    return JSON.parse(res.body);
}

/**
 * Bayar reservasi.
 * @param {string} jwt - JWT token
 * @param {number} reservationId - ID reservasi
 * @returns {object} { response, body, duration }
 */
function pay(jwt, reservationId) {
    const res = http.post(
        `${BASE_URL}/payments/pay`,
        JSON.stringify({ reservation_id: reservationId }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
            tags: { name: "POST /payments/pay" },
        }
    );

    return {
        response: res,
        body: res.body ? JSON.parse(res.body) : null,
        duration: res.timings.duration,
    };
}

/**
 * Ambil daftar reservasi user.
 * @param {string} jwt - JWT token
 * @returns {object} HTTP response
 */
function getReservations(jwt) {
    return http.get(`${BASE_URL}/reservations`, {
        headers: { Authorization: `Bearer ${jwt}` },
        tags: { name: "GET /reservations" },
    });
}

/**
 * Ambil detail reservasi tertentu.
 * @param {string} jwt - JWT token
 * @param {number} id - ID reservasi
 * @returns {object} HTTP response
 */
function getReservationDetail(jwt, id) {
    return http.get(`${BASE_URL}/reservations/${id}`, {
        headers: { Authorization: `Bearer ${jwt}` },
        tags: { name: "GET /reservations/:id" },
    });
}

// =============================================================================
// Setup — Digunakan oleh Skenario B untuk membuat satu reservasi bersama
// =============================================================================

export function setup() {
    // Skenario B membutuhkan satu reservasi yang akan dicoba bayar oleh 2000 user
    // Login sebagai user pertama dan buat reservasi
    const setupUser = users[0];
    const jwt = login(setupUser.email);

    if (!jwt) {
        console.error("SETUP GAGAL: tidak bisa login sebagai user pertama");
        return { setupToken: null, setupReservationId: null };
    }

    // Request queue token
    const queueToken = getQueueToken(jwt, VENUE_ID);
    if (!queueToken) {
        console.error("SETUP GAGAL: tidak bisa dapat queue token");
        return { setupToken: jwt, setupReservationId: null };
    }

    // Hold seat khusus untuk race condition test (seat 99999 — diluar range skenario lain)
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
        console.error(`SETUP GAGAL: hold seat 99999 — status ${holdRes.status}: ${holdRes.body}`);
        return { setupToken: jwt, setupReservationId: null };
    }

    const holdBody = JSON.parse(holdRes.body);
    // Cari reservation_id dari response
    const reservationId =
        holdBody.data?.reservation?.id ||
        holdBody.data?.reservation_id ||
        holdBody.data?.id ||
        null;

    console.log(`SETUP OK: user=${setupUser.email}, reservation_id=${reservationId}`);

    return {
        setupToken: jwt,
        setupReservationId: reservationId,
        setupUserEmail: setupUser.email,
    };
}

// =============================================================================
// Skenario A: Spike Payment Test
// =============================================================================

/**
 * Simulasi ticket war: login → queue → hold seat unik → bayar.
 * Setiap VU mendapat seat_id unik dari range 1-5000.
 */
export function spikePayment() {
    // Pilih user berdasarkan VU number (rotasi jika VU > jumlah user)
    const userIndex = (__VU - 1) % users.length;
    const user = users[userIndex];

    group("Skenario A: Spike Payment", function () {
        // 1. Login
        const jwt = login(user.email);
        if (!jwt) return;

        // 2. Queue token
        const queueToken = getQueueToken(jwt, VENUE_ID);
        if (!queueToken) return;

        // 3. Hold seat — seat_id unik per VU+iteration (range 1-5000)
        // Formula: baseOffset + (vuIndex * maxIterations) + iteration
        const seatId = ((__VU - 1) * 3 + __ITER) % 5000 + 1;

        const holdResult = holdSeat(jwt, VENUE_ID, seatId, queueToken);
        if (!holdResult) return;

        // Ambil reservation_id dari response hold
        const reservationId =
            holdResult.data?.reservation?.id ||
            holdResult.data?.reservation_id ||
            holdResult.data?.id;

        if (!reservationId) {
            console.error(`RESERVATION ID TIDAK DITEMUKAN di response hold`);
            return;
        }

        // 4. Pay — endpoint yang dioptimasi Redis
        const payResult = pay(jwt, reservationId);
        paymentDuration.add(payResult.duration);

        // 5. Verifikasi payment
        check(payResult.response, {
            "payment: status 200": (r) => r.status === 200,
            "payment: ada ticket": () =>
                payResult.body?.data?.ticket !== undefined &&
                payResult.body?.data?.ticket !== null,
            "payment: response < 500ms": () => payResult.duration < 500,
        });
    });

    sleep(0.5); // Sedikit jeda antar iterasi
}

// =============================================================================
// Skenario B: Race Condition Test
// =============================================================================

/**
 * 2000 user mencoba bayar reservation_id yang SAMA.
 * Expected: hanya 1 yang sukses, 1999 ditolak.
 */
export function raceCondition(data) {
    const userIndex = (__VU - 1) % users.length;
    const user = users[userIndex];

    group("Skenario B: Race Condition", function () {
        // Login sebagai user masing-masing
        const jwt = login(user.email);
        if (!jwt) {
            paymentErrorCount.add(1);
            return;
        }

        // Coba bayar reservation milik user pertama (dari setup)
        if (!data.setupReservationId) {
            console.error("SKIP: setupReservationId tidak tersedia");
            paymentErrorCount.add(1);
            return;
        }

        const payResult = pay(jwt, data.setupReservationId);

        if (payResult.response.status === 200) {
            // Berhasil bayar — seharusnya hanya 1 yang bisa
            paymentSuccessCount.add(1);
            console.log(`SUKSES BAYAR: user=${user.email}`);
        } else if (payResult.response.status === 422) {
            // Ditolak — expected behavior untuk 1999 user lainnya
            paymentRejectedCount.add(1);
        } else if (payResult.response.status >= 500) {
            // Server error — ini yang tidak boleh terjadi
            paymentErrorCount.add(1);
            console.error(
                `SERVER ERROR ${payResult.response.status}: user=${user.email} body=${payResult.response.body}`
            );
        } else {
            // Status lain (401, 403, dll)
            paymentRejectedCount.add(1);
        }

        // Pastikan tidak ada response 500
        check(payResult.response, {
            "race: bukan server error (bukan 5xx)": (r) => r.status < 500,
            "race: response valid (200 atau 422)": (r) =>
                r.status === 200 || r.status === 422,
        });
    });
}

// =============================================================================
// Skenario C: Mixed Read/Write Workload
// =============================================================================

/**
 * Beban campuran: 70% read (GET reservasi), 30% write (hold + pay baru).
 * Menguji stabilitas Redis cache dan write-through consistency.
 */
export function mixedWorkload() {
    const userIndex = (__VU - 1) % users.length;
    const user = users[userIndex];

    // Login di awal setiap iterasi
    const jwt = login(user.email);
    if (!jwt) {
        errorRate.add(1);
        sleep(1);
        return;
    }

    // Random: 70% read, 30% write
    const roll = Math.random();

    if (roll < 0.7) {
        // =====================================================================
        // READ — Cek daftar reservasi + detail (menguji Redis cache read)
        // =====================================================================
        group("Skenario C: Read Reservasi", function () {
            const startRead = Date.now();

            // GET daftar reservasi user
            const listRes = getReservations(jwt);
            const listOk = check(listRes, {
                "read-list: status 200": (r) => r.status === 200,
                "read-list: valid JSON": (r) => {
                    try {
                        JSON.parse(r.body);
                        return true;
                    } catch {
                        return false;
                    }
                },
                "read-list: bukan 500": (r) => r.status < 500,
            });

            errorRate.add(listRes.status >= 500 ? 1 : 0);

            // Jika ada reservasi, ambil detail yang pertama
            if (listOk && listRes.status === 200) {
                try {
                    const body = JSON.parse(listRes.body);
                    const reservations = body.data?.reservations || body.data || [];

                    if (Array.isArray(reservations) && reservations.length > 0) {
                        // Ambil detail reservasi random
                        const randomRes =
                            reservations[Math.floor(Math.random() * reservations.length)];
                        const resId = randomRes.id || randomRes.reservation_id;

                        if (resId) {
                            const detailRes = getReservationDetail(jwt, resId);
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

            const readTime = Date.now() - startRead;
            readDuration.add(readTime);
        });
    } else {
        // =====================================================================
        // WRITE — Queue → Hold seat baru → Pay (menguji write-through cache)
        // =====================================================================
        group("Skenario C: Write Payment", function () {
            const startWrite = Date.now();

            // Queue token
            const queueToken = getQueueToken(jwt, VENUE_ID);
            if (!queueToken) {
                errorRate.add(1);
                return;
            }

            // Hold seat — range 5001-10000 (terpisah dari Skenario A)
            const seatId = ((__VU - 1) * 3 + __ITER) % 5000 + 5001;

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
                    tags: { name: "POST /reservations/hold" },
                }
            );

            if (holdRes.status !== 201) {
                // Seat mungkin sudah diambil — wajar di stress test
                check(holdRes, {
                    "write-hold: bukan 500": (r) => r.status < 500,
                });
                errorRate.add(holdRes.status >= 500 ? 1 : 0);

                const writeTime = Date.now() - startWrite;
                writeDuration.add(writeTime);
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
                return;
            }

            // Pay — ini yang menguji Redis write-through
            const payResult = pay(jwt, reservationId);

            check(payResult.response, {
                "write-pay: status 200 atau 422": (r) =>
                    r.status === 200 || r.status === 422,
                "write-pay: bukan 500": (r) => r.status < 500,
            });

            errorRate.add(payResult.response.status >= 500 ? 1 : 0);

            const writeTime = Date.now() - startWrite;
            writeDuration.add(writeTime);
        });
    }

    sleep(1); // Think time — simulasi user nyata
}

// =============================================================================
// Handle Summary — Ringkasan hasil test di console
// =============================================================================

export function handleSummary(data) {
    // Ambil metrik yang relevan
    const metrics = data.metrics;

    const summary = {
        // Header
        "=== HASIL STRESS TEST PAYMENT (Redis Optimized) ===": "",

        // Skenario A
        "--- Skenario A: Spike Payment ---": "",
        "  Payment p(95) duration": metrics.payment_duration
            ? `${metrics.payment_duration.values["p(95)"].toFixed(2)} ms`
            : "N/A",
        "  Hold p(95) duration": metrics.hold_duration
            ? `${metrics.hold_duration.values["p(95)"].toFixed(2)} ms`
            : "N/A",

        // Skenario B
        "--- Skenario B: Race Condition ---": "",
        "  Payment sukses (seharusnya 1)": metrics.payment_success_count
            ? metrics.payment_success_count.values.count
            : 0,
        "  Payment ditolak": metrics.payment_rejected_count
            ? metrics.payment_rejected_count.values.count
            : 0,
        "  Payment error (5xx)": metrics.payment_error_count
            ? metrics.payment_error_count.values.count
            : 0,

        // Skenario C
        "--- Skenario C: Mixed Workload ---": "",
        "  Read p(95) duration": metrics.read_duration
            ? `${metrics.read_duration.values["p(95)"].toFixed(2)} ms`
            : "N/A",
        "  Write p(95) duration": metrics.write_duration
            ? `${metrics.write_duration.values["p(95)"].toFixed(2)} ms`
            : "N/A",
        "  Error rate": metrics.error_rate
            ? `${(metrics.error_rate.values.rate * 100).toFixed(2)}%`
            : "N/A",

        // Global
        "--- Global ---": "",
        "  Total HTTP requests": metrics.http_reqs
            ? metrics.http_reqs.values.count
            : 0,
        "  Overall p(95) duration": metrics.http_req_duration
            ? `${metrics.http_req_duration.values["p(95)"].toFixed(2)} ms`
            : "N/A",
        "  Overall failure rate": metrics.http_req_failed
            ? `${(metrics.http_req_failed.values.rate * 100).toFixed(2)}%`
            : "N/A",
    };

    // Print ringkasan ke console
    console.log("\n");
    for (const [key, value] of Object.entries(summary)) {
        if (value === "") {
            console.log(`\n${key}`);
        } else {
            console.log(`${key}: ${value}`);
        }
    }
    console.log("\n");

    // Return default summary (stdout + JSON file)
    return {
        stdout: textSummary(data, { indent: "  ", enableColors: true }),
        "payment-stress-result.json": JSON.stringify(data, null, 2),
    };
}

// Import textSummary untuk default output
import { textSummary } from "https://jslib.k6.io/k6-summary/0.0.2/index.js";
