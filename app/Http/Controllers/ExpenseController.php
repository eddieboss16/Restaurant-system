<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Expense::with('recordedBy:id,name')->latest('incurred_on')->latest('id');

        if ($from = $request->query('from')) {
            $query->whereDate('incurred_on', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('incurred_on', '<=', $to);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        return response()->json($query->limit(200)->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'description' => 'required|string|max:255',
            'incurred_on' => 'required|date',
        ]);

        $expense = Expense::create($data + ['recorded_by' => $request->user()->id]);

        return response()->json($expense->load('recordedBy:id,name'), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        // Manager can only edit their own; admin can edit any.
        if (! $request->user()->isAdmin() && $expense->recorded_by !== $request->user()->id) {
            abort(403, 'You can only edit expenses you recorded.');
        }

        $data = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'category' => ['sometimes', Rule::in(Expense::CATEGORIES)],
            'description' => 'sometimes|string|max:255',
            'incurred_on' => 'sometimes|date',
        ]);

        $expense->update($data);

        return response()->json($expense->fresh('recordedBy:id,name'));
    }

    public function destroy(Request $request, Expense $expense): JsonResponse
    {
        // Only admin can delete.
        if (! $request->user()->isAdmin()) {
            abort(403, 'Only admins can delete expenses.');
        }

        $expense->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
