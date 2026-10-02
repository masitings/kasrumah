<?php

namespace App\Http\Controllers;

use App\Support\Categories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $budgets = $request->user()->budgets()->get()->keyBy('category');

        $rows = collect(Categories::ALL)->map(fn ($label, $key) => [
            'key' => $key,
            'label' => $label,
            'monthly_limit' => $budgets->has($key) ? (int) $budgets[$key]->monthly_limit : null,
        ])->values();

        return Inertia::render('kas/budgets', ['rows' => $rows]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.key' => ['required', 'string', 'max:50'],
            'rows.*.monthly_limit' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);

        $user = $request->user();

        foreach ($validated['rows'] as $row) {
            if (! in_array($row['key'], Categories::keys(), true)) {
                continue;
            }

            $limit = (int) ($row['monthly_limit'] ?? 0);

            $budget = $user->budgets()->where('category', $row['key'])->first();

            if ($limit === 0) {
                $budget?->delete();

                continue;
            }

            $budget
                ? $budget->update(['monthly_limit' => $limit])
                : $user->budgets()->create([
                    'category' => $row['key'],
                    'monthly_limit' => $limit,
                ]);
        }

        return back()->with('success', 'Budget bulanan disimpan.');
    }
}
