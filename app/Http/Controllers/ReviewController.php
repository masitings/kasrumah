<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Support\Categories;
use App\Support\SummaryCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $expenses = $request->user()
            ->expenses()
            ->where('status', 'draft')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Expense $expense) => $this->transform($expense));

        return Inertia::render('kas/review', [
            'expenses' => $expenses,
            'categories' => Categories::ALL,
        ]);
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize($request, $expense);

        $validated = $request->validate([
            'spent_on' => ['required', 'date'],
            'merchant' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'category' => ['required', 'string', 'max:50'],
        ]);

        if (! in_array($validated['category'], Categories::keys(), true)) {
            return back()->withErrors(['category' => 'Kategori tidak dikenal.']);
        }

        $validated['amount'] = (int) preg_replace('/\D/', '', (string) $validated['amount']);

        $expense->fill($validated);

        if ($request->boolean('confirm')) {
            $expense->status = 'confirmed';
        }

        $expense->save();

        SummaryCache::forget($request->user());

        return back()->with('success', 'Pengeluaran diperbarui.');
    }

    public function confirm(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize($request, $expense);

        $expense->update(['status' => 'confirmed']);

        SummaryCache::forget($request->user());

        return back()->with('success', 'Pengeluaran disimpan.');
    }

    public function confirmAll(Request $request): RedirectResponse
    {
        $affected = $request->user()
            ->expenses()
            ->where('status', 'draft')
            ->update(['status' => 'confirmed']);

        SummaryCache::forget($request->user());

        return back()->with('success', "{$affected} pengeluaran disimpan.");
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize($request, $expense);

        if ($expense->image_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($expense->image_path);
        }

        $expense->delete();

        SummaryCache::forget($request->user());

        return back()->with('success', 'Pengeluaran dihapus.');
    }

    private function transform(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'spent_on' => $expense->spent_on->toDateString(),
            'merchant' => $expense->merchant,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'category' => $expense->category,
            'source' => $expense->source,
            'confidence' => $expense->raw_extraction['confidence'] ?? null,
            'image_url' => $expense->image_path ? route('receipts.show', $expense) : null,
        ];
    }

    private function authorize(Request $request, Expense $expense): void
    {
        abort_unless($expense->user_id === $request->user()->id, 404);
    }
}