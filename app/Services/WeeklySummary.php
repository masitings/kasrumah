<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\User;
use App\Support\Categories;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Numbers are computed in PHP; Gemma only turns them into a friendly paragraph.
 * That keeps the math trustworthy and the model's job small.
 */
class WeeklySummary
{
    public function for(User $user, ?Carbon $weekStart = null): array
    {
        $start = ($weekStart ?? Carbon::now('Asia/Jakarta'))->copy()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $thisWeek = $this->totals($user, $start, $end);
        $avgWeek = $this->averagePerWeek($user, $start->copy()->subWeeks(4), $start->copy()->subDay());
        $monthUsage = $this->monthBudgetUsage($user, $start);

        $facts = [
            'periode' => $start->toDateString().' s/d '.$end->toDateString(),
            'total_minggu_ini' => array_sum($thisWeek),
            'per_kategori_minggu_ini' => $thisWeek,
            'rata_rata_per_minggu_4_minggu_terakhir' => $avgWeek,
            'pemakaian_budget_bulan_ini_persen' => $monthUsage,
        ];

        return [
            'facts' => $facts,
            'text' => $this->narrate($facts),
        ];
    }

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

    private function averagePerWeek(User $user, Carbon $from, Carbon $to): array
    {
        return collect($this->totals($user, $from, $to))
            ->map(fn ($v) => (int) round($v / 4))
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
        $json = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $labels = Categories::forPrompt();

        $prompt = <<<PROMPT
Kamu menulis ringkasan pengeluaran mingguan untuk seorang istri yang mengatur keuangan rumah tangga.
Gaya: bahasa Indonesia santai, hangat, seperti teman yang rapi soal uang. Tanpa menggurui, tanpa emoji berlebihan.
Panjang: 3 sampai 5 kalimat.

Isi:
1. Total minggu ini.
2. Kategori yang paling naik dibanding rata-rata, dan kalau masuk akal sebut kemungkinan sebabnya secara netral.
3. Budget bulan ini yang sudah di atas 70%, kalau ada.

Pakai HANYA angka dari data ini, jangan menghitung ulang atau mengarang angka. Tulis rupiah seperti "Rp850 rb" atau "Rp1,2 jt".

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
