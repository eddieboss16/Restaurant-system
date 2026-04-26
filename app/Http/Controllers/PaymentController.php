<?php

namespace App\Http\Controllers;

use App\Models\CustomerSession;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class PaymentController extends Controller
{
    public function store(Request $request, CustomerSession $session, SessionService $sessions): JsonResponse
    {
        $this->authorize('collectPayment', $session);

        $data = $request->validate([
            'method' => 'required|in:cash,mpesa',
            'amount' => 'required|numeric|min:0',
            'mpesa_code' => 'required_if:method,mpesa|nullable|string',
        ]);

        try {
            $payment = $sessions->recordPayment(
                $session,
                $data,
                $request->user()->id
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($payment, 201);
    }
}
