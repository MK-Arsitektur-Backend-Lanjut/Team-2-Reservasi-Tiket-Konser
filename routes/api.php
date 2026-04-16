<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TicketController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\SeatController;

Route::middleware('auth:sanctum')->group(function (): void {
    // Venue & Seat
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{id}', [VenueController::class, 'show']);
    Route::get('/venues/{venueId}/seats', [SeatController::class, 'index']);
    
    // Payments & Tickets
    Route::post('/payments/pay', [PaymentController::class, 'pay']);
    Route::get('/tickets/{reservation}', [TicketController::class, 'showByReservation']);
});
