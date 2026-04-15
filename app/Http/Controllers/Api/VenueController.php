<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Services\VenueService;

class VenueController extends Controller
{
    protected $venueService;

    public function __construct(VenueService $venueService)
    {
        $this->venueService = $venueService;
    }

    public function index()
    {
        try {
            $venues = $this->venueService->getAllVenues();
            
            return response()->json([
                'status' => true,
                'message' => 'Berhasil mengambil data venues',
                'data' => $venues
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data venues',
                'errors' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $venue = $this->venueService->getVenueDetails($id);
            
            return response()->json([
                'status' => true,
                'message' => 'Berhasil mengambil detail venue',
                'data' => $venue
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Venue tidak ditemukan',
                'errors' => $e->getMessage()
            ], 404);
        }
    }
}
