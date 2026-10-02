<?php

namespace App\Http\Controllers;

use App\Services\ExpenseExtractor;
use App\Support\SummaryCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CatatController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('kas/catat');
    }

    public function storeText(Request $request, ExpenseExtractor $extractor): RedirectResponse
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:500'],
        ]);

        $extracted = $extractor->fromText($validated['text']);

        if (empty($extracted)) {
            return back()->withErrors([
                'text' => 'Catatan tidak terbaca atau nominalnya tidak jelas. Coba ketik lagi, misal: "beli sayur 45rb".',
            ]);
        }

        $user = $request->user();

        foreach ($extracted as $row) {
            $user->expenses()->create([
                'spent_on' => $row['spent_on'],
                'merchant' => $row['merchant'] ?? null,
                'description' => $row['description'] ?? null,
                'amount' => (int) $row['amount'],
                'category' => $row['category'],
                'source' => 'text',
                'status' => 'draft',
                'raw_extraction' => $row['raw'] ?? $row,
            ]);
        }

        SummaryCache::forget($request->user());

        return redirect()->route('review.index')->with('success', count($extracted).' pengeluaran berhasil dicatat sebagai draf.');
    }

    public function storeImage(Request $request, ExpenseExtractor $extractor): RedirectResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:15360'],
        ]);

        $file = $request->file('image');
        $user = $request->user();

        $ext = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = Str::ulid().'.'.$ext;
        $relativeDir = "receipts/{$user->id}";
        $path = $file->storeAs($relativeDir, $filename, 'local');

        $fullPath = Storage::disk('local')->path($path);

        try {
            $extracted = $extractor->fromImage($fullPath);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);

            return back()->withErrors([
                'image' => 'Gagal membaca struk: '.$e->getMessage(),
            ]);
        }

        if (empty($extracted)) {
            Storage::disk('local')->delete($path);

            return back()->withErrors([
                'image' => 'Struk tidak terbaca. Pastikan foto cukup terang dan nominal total terlihat jelas.',
            ]);
        }

        $row = $extracted[0];

        $user->expenses()->create([
            'spent_on' => $row['spent_on'],
            'merchant' => $row['merchant'] ?? null,
            'description' => $row['description'] ?? null,
            'amount' => (int) $row['amount'],
            'category' => $row['category'],
            'source' => $row['source'] ?? 'receipt',
            'status' => 'draft',
            'image_path' => $path,
            'raw_extraction' => $row['raw'] ?? $row,
        ]);

        SummaryCache::forget($request->user());

        return redirect()->route('review.index')->with('success', 'Struk berhasil dibaca dan masuk ke draf.');
    }
}
