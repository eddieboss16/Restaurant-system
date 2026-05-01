<?php

namespace App\Services;

use App\Models\CustomerSession;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SessionService
{
    public function __construct(private ReceiptService $receipts)
    {
    }

    public function open(int $waiterId, ?string $customerLabel): CustomerSession
    {
        return CustomerSession::create([
            'waiter_id' => $waiterId,
            'customer_label' => $customerLabel,
            'status' => 'open',
            'opened_at' => now(),
        ]);
    }

    public function recordPayment(CustomerSession $session, array $data, int $waiterId): Payment
    {
        if ($data['method'] === 'mpesa' && empty($data['mpesa_code'])) {
            throw new InvalidArgumentException('Mpesa code is required for Mpesa payments.');
        }

        $outstandingOrders = $session->orders()
            ->whereIn('status', ['pending', 'preparing'])
            ->exists();

        if ($outstandingOrders) {
            throw new RuntimeException('Cannot collect payment while orders are still pending or preparing.');
        }

        $payment = DB::transaction(function () use ($session, $data, $waiterId) {
            $payment = Payment::create([
                'session_id' => $session->id,
                'method' => $data['method'],
                'amount' => $data['amount'],
                'mpesa_code' => $data['mpesa_code'] ?? null,
                'collected_by' => $waiterId,
                'confirmed_at' => now(),
            ]);

            $session->update([
                'status' => 'paid',
                'closed_at' => now(),
            ]);

            return $payment;
        });

        // Auto-queue the receipt for printing. Outside the transaction so a
        // print-job insert failure doesn't roll back the payment itself.
        $this->receipts->queueForSession($session->fresh(), $waiterId);

        return $payment;
    }
}
