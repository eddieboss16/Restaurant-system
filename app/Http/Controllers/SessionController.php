<?php

namespace App\Http\Controllers;

use App\Models\CustomerSession;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()
            ->customerSessions()
            ->with(['orders.menuItem', 'payment'])
            ->whereNotIn('status', ['paid']);

        if ($request->user()->isManager()) {
            $query = CustomerSession::query()
                ->with(['waiter:id,name', 'orders.menuItem', 'payment'])
                ->whereNotIn('status', ['paid']);
        }

        return response()->json($query->latest('opened_at')->get());
    }

    public function store(Request $request, SessionService $sessions): JsonResponse
    {
        $data = $request->validate([
            'customer_label' => 'nullable|string|max:100',
        ]);

        $session = $sessions->open(
            $request->user()->id,
            $data['customer_label'] ?? null
        );

        return response()->json($session, 201);
    }

    public function show(Request $request, CustomerSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        return response()->json(
            $session->load(['orders.menuItem', 'payment', 'waiter:id,name'])
        );
    }
}
