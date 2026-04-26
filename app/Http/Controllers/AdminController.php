<?php

namespace App\Http\Controllers;

use App\Models\CancellationLog;
use App\Models\MenuItem;
use App\Models\Resource;
use App\Models\ResourceTransaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    // ----- Staff -----

    public function listStaff(): JsonResponse
    {
        return response()->json(
            User::orderBy('role')->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'is_active', 'pin'])
        );
    }

    public function createStaff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(['waiter', 'kitchen', 'manager', 'admin'])],
            'pin' => 'nullable|string|size:4',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'pin' => $data['pin'] ?? null,
            'is_active' => true,
        ]);

        return response()->json($user->only('id', 'name', 'email', 'role', 'is_active'), 201);
    }

    public function updateStaff(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'role' => ['sometimes', Rule::in(['waiter', 'kitchen', 'manager', 'admin'])],
            'pin' => 'nullable|string|size:4',
            'is_active' => 'sometimes|boolean',
            'password' => 'nullable|string|min:8',
        ]);

        if ($user->id === $request->user()->id && array_key_exists('is_active', $data) && $data['is_active'] === false) {
            abort(422, 'You cannot deactivate your own account.');
        }

        if ($user->id === $request->user()->id && isset($data['role']) && $data['role'] !== 'admin') {
            abort(422, 'You cannot demote your own admin account.');
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        return response()->json($user->only('id', 'name', 'email', 'role', 'is_active'));
    }

    // ----- Menu items -----

    public function listMenuItems(): JsonResponse
    {
        return response()->json(
            MenuItem::orderBy('category')->orderBy('name')->get()
        );
    }

    public function createMenuItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:menu_items,name',
            'price' => 'required|numeric|min:0',
            'category' => 'nullable|string|max:50',
            'is_available' => 'sometimes|boolean',
        ]);

        $item = MenuItem::create($data + ['is_available' => $data['is_available'] ?? true]);

        return response()->json($item, 201);
    }

    public function updateMenuItem(Request $request, MenuItem $menuItem): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('menu_items', 'name')->ignore($menuItem->id)],
            'price' => 'sometimes|numeric|min:0',
            'category' => 'nullable|string|max:50',
            'is_available' => 'sometimes|boolean',
        ]);

        $menuItem->update($data);

        return response()->json($menuItem->fresh());
    }

    public function deleteMenuItem(MenuItem $menuItem): JsonResponse
    {
        if ($menuItem->orders()->exists()) {
            abort(422, 'Cannot delete a menu item with order history. Toggle availability instead.');
        }

        $menuItem->recipeIngredients()->delete();
        $menuItem->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    // ----- Resources -----

    public function listResources(): JsonResponse
    {
        return response()->json(
            Resource::orderBy('name')->get()->map(fn ($r) => $r->toArray() + ['low_stock' => $r->isLowStock()])
        );
    }

    public function updateResource(Request $request, Resource $resource): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:50',
            'unit' => 'sometimes|string|max:20',
            'low_stock_threshold' => 'sometimes|numeric|min:0',
        ]);

        $resource->update($data);

        return response()->json($resource->fresh());
    }

    public function restockResource(Request $request, Resource $resource): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.001',
            'reason' => 'nullable|string|max:200',
        ]);

        DB::transaction(function () use ($resource, $data, $request) {
            $resource->increment('current_stock', $data['amount']);
            $resource->update(['last_restocked_at' => now()]);

            ResourceTransaction::create([
                'resource_id' => $resource->id,
                'change_amount' => $data['amount'],
                'type' => 'manual_restock',
                'reason' => $data['reason'] ?? null,
                'triggered_by' => $request->user()->id,
            ]);
        });

        return response()->json($resource->fresh());
    }

    // ----- Cancellation log -----

    public function listCancellations(): JsonResponse
    {
        return response()->json(
            CancellationLog::with(['order.menuItem', 'cancelledBy:id,name'])
                ->latest('cancelled_at')
                ->limit(100)
                ->get()
        );
    }
}
