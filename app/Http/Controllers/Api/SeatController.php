<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Services\SeatService;

class SeatController extends Controller
{
    protected $seatService;

    public function __construct(SeatService $seatService)
    {
        $this->seatService = $seatService;
    }

    public function index($venueId, Request $request)
    {
        try {
            $isAvailableOnly = $request->query('available', false);
            $category = $request->query('category');
            
            if ($isAvailableOnly) {
                $seats = $this->seatService->getAvailableSeats($venueId, $category);
            } else {
                $seats = $this->seatService->getSeatsByVenue($venueId);
            }
            
            return response()->json([
                'status' => true,
                'message' => 'Berhasil',
                'data' => $seats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data kursi',
                'errors' => $e->getMessage()
            ], 500);
        }
    }
}
