import http from "k6/http";
import { check } from "k6";
import { SharedArray } from "k6/data";

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

const BASE_URL = "http://127.0.0.1:8000/api";

export const options = {
    vus: 100,
    duration: "60s",
};

export default function () {
    const user = users[(__VU - 1) % users.length];

    // LOGIN
    const loginRes = http.post(
        `${BASE_URL}/auth/login`,
        JSON.stringify({
            email: user.email,
            password: "password123",
        }),
        {
            headers: {
                "Content-Type": "application/json",
            },
        },
    );

    const loginOk = check(loginRes, {
        "login success": (r) => r.status === 200,
    });

    if (!loginOk) {
        console.log(`LOGIN FAIL: ${user.email}`);
        return;
    }

    const jwt = JSON.parse(loginRes.body).data.token;

    // QUEUE TOKEN
    const queueRes = http.post(
        `${BASE_URL}/reservations/queue-token`,
        JSON.stringify({
            venue_id: 1,
        }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        },
    );

    const queueOk = check(queueRes, {
        "queue token success": (r) => r.status === 200,
    });

    if (!queueOk) {
        console.log(`QUEUE FAIL: ${user.email}`);
        console.log(queueRes.body);
        return;
    }

    const queueToken = JSON.parse(queueRes.body).data.token;

    // SEMUA USER REBUTAN KURSI YANG SAMA
    const seatId = 9999;

    // HOLD
    const holdRes = http.post(
        `${BASE_URL}/reservations/hold`,
        JSON.stringify({
            venue_id: 1,
            seat_id: seatId,
            queue_token: queueToken,
        }),
        {
            headers: {
                Authorization: `Bearer ${jwt}`,
                "Content-Type": "application/json",
            },
        },
    );

    check(holdRes, {
        "hold processed": (r) => r.status === 201 || r.status === 422,
    });

    if (holdRes.status === 201) {
        console.log(`SUCCESS HOLD seat=${seatId} user=${user.email}`);
    } else if (holdRes.status === 422) {
        console.log(`REJECTED seat=${seatId} user=${user.email}`);
    } else {
        console.log(`UNEXPECTED ${holdRes.status}`);
        console.log(holdRes.body);
    }
}
