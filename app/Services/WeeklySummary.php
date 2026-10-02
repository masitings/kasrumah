<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use App\Support\Categories;
use App\Support\Rupiah;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Numbers are computed in PHP; Gemma only turns them into a friendly paragraph.
 * That keeps the math trustworthy and the model's job small. Every money figure
 * is already formatted as an Indonesian rupiah label before it reaches the prompt.
 */
class WeeklySummary
{
    public function for(User $user, ?Carbon $weekStart = null): array
    {
        $start = ($weekStart ?? Carbon::now('Asia/Jakarta'))->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $thisWeek = $this->totals($user, $start, $end);
        $previousWeeks = $this->totals($user, $start->copy()->subWeeks(4), $start->copy()->subDay());
        $monthUsage = $this->monthBudgetUsage($user, $start);

        $average = $previousWeeks === [] ? null : $this->averagePerWeek($previousWeeks);

        $facts = [
            'periode' => $start->toDateString().' s/d '.$end->toDateString(),
            'total_minggu_ini' => Rupiah::short(array_sum($thisWeek)),
            'per_kategori_minggu_ini' => Rupiah::shortMap($thisWeek),
            // No confirmed expenses in the previous four weeks -> no average to compare against.
            'rata_rata' => $average === null ? null : Rupiah::shortMap($average),
            'pemakaian_budget_bulan_ini_persen' => $monthUsage,
            // Non-money helper: categories that rose above their 4-week average.
            'kategori_naik' => $this->categoriesAboveAverage($thisWeek, $average),
        ];

        return [
            'facts' => $facts,
            'text' => $this->narrate($facts),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function totals(User $user, Carbon $from, Carbon $to): array
    {
        return Expense::where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function averagePerWeek(array $totals): array
    {
        return collect($totals)
            ->map(fn ($v) => (int) round($v / 4))
            ->all();
    }

    /**
     * @param  array<string, int>  $thisWeek
     * @param  array<string, int>|null  $average
     * @return list<string>
     */
    private function categoriesAboveAverage(array $thisWeek, ?array $average): array
    {
        if ($average === null) {
            return [];
        }

        return collect($thisWeek)
            ->filter(fn (int $total, string $category) => $total > ($average[$category] ?? 0) && ($average[$category] ?? 0) > 0)
            ->keys()
            ->values()
            ->all();
    }

    private function monthBudgetUsage(User $user, Carbon $ref): array
    {
        $spent = $this->totals($user, $ref->copy()->startOfMonth(), $ref->copy()->endOfMonth());

        return $user->budgets()
            ->get()
            ->mapWithKeys(fn ($b) => [
                $b->category => $b->monthly_limit > 0
                    ? (int) round(($spent[$b->category] ?? 0) / $b->monthly_limit * 100)
                    : 0,
            ])
            ->all();
    }

    private function narrate(array $facts): string
    {
        $json = json_encode($facts, JSON_PRETTY_PRINT);
        $labels = Categories::forPrompt();

        $prompt = <<<PROMPT
Kamu menulis ringkasan pengeluaran mingguan untuk seorang istri yang mengatur keuangan rumah tangga.
Gaya: bahasa Indonesia santai, hangat, seperti teman yang rapi soal uang. Tanpa menggurui, tanpa emoji berlebihan.
Panjang: 3 sampai 5 kalimat.

Isi:
1. Total minggu ini.
2. Kalau "rata_rata" bukan null: kategori yang paling naik dibanding rata-rata, dan kalau masuk akal sebut kemungkinan sebabnya secara netral. Kalau "rata_rata" null, JANGAN bandingkan dengan rata-rata -- cukup sebut total dan kategori terbesar minggu ini.
3. Budget bulan ini yang sudah di atas 70%, kalau ada.

Pakai HANYA nilai dari data ini, jangan menghitung ulang atau mengarang angka. Semua rupiah sudah berbentuk teks siap pakai (contoh: "Rp329 rb", "Rp1,25 jt") -- kutip persis apa adanya, jangan ubah formatnya.

Arti kategori:
{$labels}

Data:
{$json}
PROMPT;

        return Http::timeout(120)
            ->post(config('services.ollama.url').'/api/chat', [
                'model' => config('services.ollama.model'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'stream' => false,
                'options' => ['temperature' => 0.4],
            ])
            ->throw()
            ->json('message.content', '');
    }
}
