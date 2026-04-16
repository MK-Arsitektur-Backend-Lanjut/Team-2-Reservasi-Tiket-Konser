<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\VenueController;
use App\Http\Controllers\Api\SeatController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function (): void {

    // Venue & Seat
    Route::get('/venues', [VenueController::class, 'index']);
    Route::get('/venues/{id}', [VenueController::class, 'show']);
    Route::get('/venues/{venueId}/seats', [SeatController::class, 'index']);

    // Reservation & Queue Control
    Route::post('/reservations/queue-token', [ReservationController::class, 'requestToken']);
    Route::post('/reservations/hold', [ReservationController::class, 'hold']);
    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::get('/reservations/{id}', [ReservationController::class, 'show']);
    Route::delete('/reservations/{id}/release', [ReservationController::class, 'release']);

    // Payments & Tickets
    Route::post('/payments/pay', [PaymentController::class, 'pay']);
    Route::get('/tickets/{reservation}', [TicketController::class, 'showByReservation']);
});

