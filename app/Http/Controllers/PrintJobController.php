<?php

namespace App\Http\Controllers;

use App\Models\CustomerSession;
use App\Models\PrintJob;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrintJobController extends Controller
{
    /**
     * Waiter (or manager/admin) requests a receipt print for a session.
     * Idempotency: re-queueing for the same session is allowed -- if the
     * waiter taps Print again, they get another copy.
     */
    public function queue(Request $request, CustomerSession $session, ReceiptService $receipts): JsonResponse
    {
        $this->authorize('view', $session);

        $job = PrintJob::create([
            'session_id' => $session->id,
            'queued_by' => $request->user()->id,
            'payload' => $receipts->buildForSession($session),
            'status' => 'pending',
        ]);

        return response()->json($job, 202);
    }

    // ----- Bridge-only endpoints (auth via X-Bridge-Token header) -----

    /**
     * Bridge polls this. Returns the oldest pending job and atomically
     * flips it to "printing" so the next poll doesn't see it. If the bridge
     * never acks, a follow-up sweep would have to reset stuck "printing"
     * jobs (not built yet -- flagged for follow-up).
     */
    public function pending()
    {
        $job = PrintJob::query()
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->first();

        if (! $job) {
            return response()->noContent();
        }

        $job->update(['status' => 'printing']);

        return response()->json($job->fresh());
    }

    public function ack(PrintJob $printJob): JsonResponse
    {
        $printJob->update([
            'status' => 'printed',
            'printed_at' => now(),
            'error' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function fail(Request $request, PrintJob $printJob): JsonResponse
    {
        $data = $request->validate([
            'error' => 'required|string|max:500',
        ]);

        $printJob->update([
            'status' => 'failed',
            'error' => $data['error'],
        ]);

        return response()->json(['ok' => true]);
    }
}
