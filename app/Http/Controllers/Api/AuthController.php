<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->login(
                $request->string('email')->toString(),
                $request->string('password')->toString()
            );

            return response()->json([
                'status' => true,
                'message' => 'Login berhasil',
                'data' => [
                    'token' => $result['token'],
                    'user' => $result['user'],
                ],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'message' => $exception->getMessage(),
                'errors' => [
                    'credentials' => ['Email atau password salah.'],
                ],
            ], 401);
        }
    }

    public function logout(): JsonResponse
    {
        $user = auth()->user();
        $user?->currentAccessToken()?->delete();

        return response()->json([
            'status' => true,
            'message' => 'Logout berhasil',
            'data' => null,
        ]);
    }
}
