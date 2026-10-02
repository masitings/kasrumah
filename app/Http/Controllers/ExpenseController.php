<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Support\Categories;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    /** Month switcher shows the requested period (default current month). */
    public function index(Request $request): Response
    {
        $requested = (string) $request->query('month');
        $requested = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1
            ? $requested
            : now('Asia/Jakarta')->format('Y-m');

        [$year, $month] = explode('-', $requested);

        $expenses = Expense::where('user_id', $request->user()->id)
            ->where('status', 'confirmed')
            ->whereBetween('spent_on', [
                $requested.'-01',
                $requested.'-'.now('Asia/Jakarta')->setDate((int) $year, (int) $month, 1)->endOfMonth()->toDateString(),
            ])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();

        $groups = $expenses
            ->groupBy(fn (Expense $expense) => $expense->spent_on->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'total' => (int) $rows->sum('amount'),
                'expenses' => $rows->map(fn (Expense $expense) => [
                    'id' => $expense->id,
                    'merchant' => $expense->merchant,
                    'description' => $expense->description,
                    'amount' => $expense->amount,
                    'category' => $expense->category,
                    'source' => $expense->source,
                    'image_url' => $expense->image_path ? route('receipts.show', $expense) : null,
                ])->values(),
            ])
            ->values();

        $perCategory = collect(Categories::ALL)
            ->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'total' => (int) $expenses->where('category', $key)->sum('amount'),
            ])
            ->reject(fn (array $bucket) => $bucket['total'] === 0)
            ->values();

        return Inertia::render('kas/expenses', [
            'month' => $requested,
            'monthLabel' => $start->translatedFormat('F Y'),
            'prevMonth' => $start->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $start->copy()->addMonth()->format('Y-m'),
            'groups' => $groups,
            'total' => (int) $expenses->sum('amount'),
            'perCategory' => $perCategory,
            'categories' => Categories::ALL,
        ]);
    }
}
