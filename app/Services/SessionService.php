<?php

namespace App\Services;

use App\Models\CustomerSession;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SessionService
{
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

        return DB::transaction(function () use ($session, $data, $waiterId) {
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
    }
}
