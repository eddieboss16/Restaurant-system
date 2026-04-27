<?php

namespace App\Http\Controllers;

use App\Models\CustomerSession;
use App\Models\Payment;
use App\Services\MpesaService;
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

    public function initiateStk(Request $request, CustomerSession $session, MpesaService $mpesa, SessionService $sessions): JsonResponse
    {
        $this->authorize('collectPayment', $session);

        $data = $request->validate([
            'phone' => 'required|string|min:9|max:15',
            'amount' => 'required|numeric|min:1',
        ]);

        if ($session->payment && $session->payment->status === 'completed') {
            return response()->json(['message' => 'This session has already been paid.'], 422);
        }

        if ($session->orders()->whereIn('status', ['pending', 'preparing'])->exists()) {
            return response()->json(['message' => 'Cannot collect payment while orders are still pending or preparing.'], 422);
        }

        if ($session->payment && $session->payment->status === 'pending') {
            return response()->json(['message' => 'An STK push is already in progress for this session. Wait for the customer to respond or for it to time out.'], 422);
        }

        $payment = Payment::create([
            'session_id' => $session->id,
            'method' => 'mpesa',
            'amount' => $data['amount'],
            'phone_number' => $mpesa->normalizePhone($data['phone']),
            'status' => 'pending',
            'collected_by' => $request->user()->id,
        ]);

        try {
            $result = $mpesa->initiateStkPush(
                phone: $data['phone'],
                amount: (float) $data['amount'],
                accountReference: 'SESSION'.$session->id,
                description: 'Restaurant tab',
            );
        } catch (RuntimeException $e) {
            $payment->update([
                'status' => 'failed',
                'mpesa_result_desc' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $payment->update([
            'mpesa_checkout_request_id' => $result['checkout_request_id'],
            'mpesa_merchant_request_id' => $result['merchant_request_id'],
        ]);

        return response()->json($payment->fresh(), 202);
    }
}
