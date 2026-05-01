<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    public function callback(Request $request, ReceiptService $receipts): JsonResponse
    {
        $stk = $request->input('Body.stkCallback');

        // Daraja expects a 200 on every callback to stop retries; we always
        // ResultCode 0 in their system means: { "ResultCode": 0, "ResultDesc": "Success" }.
        $okResponse = response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

        if (! $stk || ! isset($stk['CheckoutRequestID'])) {
            Log::warning('M-Pesa callback received without stkCallback body', $request->all());
            return $okResponse;
        }

        $payment = Payment::where('mpesa_checkout_request_id', $stk['CheckoutRequestID'])->first();

        if (! $payment) {
            Log::warning('M-Pesa callback received for unknown CheckoutRequestID', ['id' => $stk['CheckoutRequestID']]);
            return $okResponse;
        }

        // Idempotent: if we've already processed this one, do nothing.
        if ($payment->status !== 'pending') {
            return $okResponse;
        }

        $succeeded = DB::transaction(function () use ($payment, $stk) {
            $resultCode = (int) ($stk['ResultCode'] ?? -1);

            if ($resultCode === 0) {
                $metadata = $this->extractMetadata($stk['CallbackMetadata']['Item'] ?? []);

                $payment->update([
                    'status' => 'completed',
                    'mpesa_code' => $metadata['MpesaReceiptNumber'] ?? null,
                    'mpesa_result_code' => 0,
                    'mpesa_result_desc' => $stk['ResultDesc'] ?? 'Success',
                    'confirmed_at' => now(),
                ]);

                $payment->session->update([
                    'status' => 'paid',
                    'closed_at' => now(),
                ]);

                return true;
            }

            $payment->update([
                'status' => 'failed',
                'mpesa_result_code' => $resultCode,
                'mpesa_result_desc' => $stk['ResultDesc'] ?? 'Failed',
            ]);
            return false;
        });

        // Auto-queue receipt on success, outside the transaction.
        if ($succeeded) {
            $receipts->queueForSession($payment->session->fresh(), $payment->collected_by);
        }

        return $okResponse;
    }

    /**
     * Daraja sends metadata as [{Name, Value}, ...]. Flatten to a name-keyed array.
     */
    private function extractMetadata(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (isset($item['Name'])) {
                $result[$item['Name']] = $item['Value'] ?? null;
            }
        }
        return $result;
    }
}
