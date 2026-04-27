<?php

namespace App\Http\Controllers;

use App\Models\CancellationLog;
use App\Models\CustomerSession;
use App\Models\MenuItem;
use App\Models\Order;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderController extends Controller
{
    public function kitchenQueue(): JsonResponse
    {
        $orders = Order::with(['menuItem:id,name,category', 'session:id,customer_label,waiter_id', 'session.waiter:id,name'])
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'preparing' THEN 2 WHEN 'ready' THEN 3 END")
            ->orderBy('created_at')
            ->get();

        return response()->json($orders);
    }

    public function store(Request $request, CustomerSession $session, InventoryService $inventory): JsonResponse
    {
        $this->authorize('addOrder', $session);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.menu_item_id' => 'required|exists:menu_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
        ]);

        try {
            $orders = DB::transaction(function () use ($data, $session, $inventory) {
                $created = [];

                foreach ($data['items'] as $item) {
                    $menuItem = MenuItem::findOrFail($item['menu_item_id']);

                    if (! $menuItem->is_available) {
                        abort(422, "{$menuItem->name} is not available.");
                    }

                    $order = Order::create([
                        'session_id' => $session->id,
                        'menu_item_id' => $menuItem->id,
                        'quantity' => $item['quantity'],
                        'unit_price' => $menuItem->price,
                        'notes' => $item['notes'] ?? null,
                        'status' => 'pending',
                    ]);

                    $inventory->deductForOrder($order);

                    $created[] = $order->load('menuItem');
                }

                if ($session->status === 'open') {
                    $session->update(['status' => 'ordered']);
                }

                return $created;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($orders, 201);
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:preparing,ready,delivered',
        ]);

        $user = $request->user();

        if ($data['status'] === 'delivered' && ! ($user->isWaiter() || $user->isManager() || $user->isAdmin())) {
            abort(403);
        }

        if (in_array($data['status'], ['preparing', 'ready'], true) && ! ($user->isKitchen() || $user->isManager() || $user->isAdmin())) {
            abort(403);
        }

        if ($order->status === 'cancelled') {
            abort(422, 'Cancelled orders cannot change status.');
        }

        $order->update(['status' => $data['status']]);

        if ($data['status'] === 'delivered') {
            $session = $order->session;
            $allDone = $session->orders()
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->doesntExist();

            if ($allDone) {
                $session->update(['status' => 'served']);
            }
        }

        return response()->json($order->fresh('menuItem'));
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|min:5',
        ]);

        $user = $request->user();

        if (! $user->isManager() && ! $user->isAdmin() && $order->session->waiter_id !== $user->id) {
            abort(403);
        }

        if ($order->status === 'delivered') {
            abort(422, 'Delivered orders cannot be cancelled.');
        }

        DB::transaction(function () use ($order, $data, $user) {
            CancellationLog::create([
                'order_id' => $order->id,
                'cancelled_by' => $user->id,
                'reason' => $data['reason'],
                'cancelled_at' => now(),
            ]);

            $order->update(['status' => 'cancelled']);
        });

        return response()->json(['message' => 'Order cancelled and logged.']);
    }
}
