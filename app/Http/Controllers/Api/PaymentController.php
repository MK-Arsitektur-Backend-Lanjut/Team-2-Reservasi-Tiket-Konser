<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PayReservationRequest;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {
    }

    public function pay(PayReservationRequest $request): JsonResponse
    {
        try {
            $ticket = $this->paymentService->payReservation(
                (int) auth()->id(),
                (int) $request->integer('reservation_id')
            );

            return response()->json([
                'status' => true,
                'message' => 'Pembayaran berhasil, tiket diterbitkan.',
                'data' => [
                    'ticket' => $ticket,
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'payment' => [$exception->getMessage()],
                ],
            ], 422);
        }
    }
}
