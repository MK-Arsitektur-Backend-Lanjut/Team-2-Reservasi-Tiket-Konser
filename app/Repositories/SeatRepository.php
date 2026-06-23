<?php

namespace App\Repositories;

use App\Models\Seat;
use App\Repositories\Interfaces\SeatRepositoryInterface;

class SeatRepository implements SeatRepositoryInterface
{
    public function getAvailableSeats($venueId, $category = null)
    {
        // [OPTIMASI: PAGINATION]
        // Mencegah server mengirim 100.000 data sekaligus, dipecah menjadi per-halaman (default 100).
        $page = request()->get('page', 1);

        // [OPTIMASI: CACHING & TAGS]
        // Menyimpan data hasil query ke dalam Redis cache. Jika request yang sama datang lagi,
        // data akan diambil dari Redis (sangat cepat) dan tidak akan query database lagi.
        $cacheKey = "available_seats_venue_{$venueId}";
        if ($category) {
            $cacheKey .= "_category_{$category}";
        }
        $cacheKey .= "_page_{$page}";

        return \Illuminate\Support\Facades\Cache::tags(["venue_{$venueId}"])->remember($cacheKey, 3600, function () use ($venueId, $category) {
            // Jika cache belum ada, jalankan query database dengan menggunakan Index yang telah dibuat.
            $query = Seat::where('venue_id', $venueId)->where('status', 'available');

            if ($category) {
                $query->where('category', $category);
            }

            return $query->paginate(100);
        });
    }

    // [OPTIMASI: QUERY OPTIMIZATION UNTUK SUMMARY]
    // Method baru ini mengambil ringkasan status seluruh kursi pada suatu venue (available, hold, sold).
    // Karena query ini bisa sangat berat (menghitung ratusan ribu baris), kita menggunakan caching dengan Redis tags.
    public function getSeatSummary($venueId)
    {
        $cacheKey = "seat_summary_venue_{$venueId}";

        return \Illuminate\Support\Facades\Cache::tags(["venue_{$venueId}"])->remember($cacheKey, 3600, function () use ($venueId) {
            return Seat::where('venue_id', $venueId)
                ->selectRaw('category, status, count(*) as count')
                ->groupBy('category', 'status')
                ->get()
                ->groupBy('category')
                ->map(function ($items) {
                    $available = $items->where('status', 'available')->first();
                    $hold = $items->where('status', 'hold')->first();
                    $sold = $items->where('status', 'sold')->first();

                    return [
                        'available' => $available ? $available->count : 0,
                        'hold' => $hold ? $hold->count : 0,
                        'sold' => $sold ? $sold->count : 0,
                        'total' => $items->sum('count')
                    ];
                });
        });
    }

    public function updateStatus($seatId, $status): Seat
    {
        $seat = Seat::findOrFail($seatId);
        $seat->update(['status' => $status]);

        // [OPTIMASI: CACHE INVALIDATION / PENGHAPUSAN CACHE]
        // Karena ada kursi yang dipesan (status berubah jadi 'hold' atau 'sold'),
        // maka data kursi lama yang ada di Redis menjadi tidak akurat.
        // Kita menghapus ("flush") semua cache yang memiliki tag venue ini
        // agar user mendapatkan data kursi paling terbaru pada request berikutnya.
        \Illuminate\Support\Facades\Cache::tags(["venue_{$seat->venue_id}"])->flush();

        return $seat;
    }

    public function findById($seatId): ?Seat
    {
        return Seat::find($seatId);
    }

    // [OPTIMASI: PAGINATION & CACHING UNTUK GET ALL SEATS]
    // Memecah output menggunakan pagination dan melakukan caching menggunakan tag redis 
    // agar pembacaan seluruh kursi sangat cepat dan tidak memblokir server.
    public function getByVenue($venueId)
    {
        $page = request()->get('page', 1);
        $cacheKey = "seats_venue_{$venueId}_page_{$page}";

        return \Illuminate\Support\Facades\Cache::tags(["venue_{$venueId}"])->remember($cacheKey, 3600, function () use ($venueId) {
            return Seat::where('venue_id', $venueId)->paginate(100);
        });
    }

    /**
     * SELECT ... FOR UPDATE — harus dipanggil dalam DB::transaction().
     * Mencegah dua request mengambil seat yang sama secara bersamaan.
     */
    public function findByIdForUpdate(int $seatId): ?Seat
    {
        return Seat::query()->lockForUpdate()->find($seatId);
    }
}

