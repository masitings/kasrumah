<?php

namespace App\Services;

use App\Support\Categories;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Turns a receipt photo, transfer screenshot, or free text into expense rows
 * using a local Gemma model through Ollama. Nothing is sent off the machine.
 */
class ExpenseExtractor
{
    public function fromImage(string $absolutePath): array
    {
        $image = base64_encode(file_get_contents($absolutePath));

        return $this->ask($this->prompt(
            'Gambar ini adalah struk belanja, screenshot bukti transfer m-banking, atau screenshot pesanan online dari Indonesia. Baca isinya.'
        ), [$image]);
    }

    public function fromText(string $text): array
    {
        return $this->ask($this->prompt(
            "Ini catatan pengeluaran yang diketik atau diucapkan: \"{$text}\""
        ));
    }

    private function prompt(string $context): string
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $categories = Categories::forPrompt();

        return <<<PROMPT
{$context}

Hari ini tanggal {$today}. Ubah menjadi daftar pengeluaran.

Aturan:
- Satu struk = satu pengeluaran (total akhir yang dibayar), jangan pecah per barang.
- Bukti transfer: ambil nominal yang dikirim dan nama penerima sebagai merchant.
- "rb" atau "ribu" = x1000, "jt" atau "juta" = x1000000. amount berupa angka bulat rupiah tanpa titik.
- Kalau tanggal tidak terlihat, pakai hari ini.
- source salah satu dari: receipt, transfer, order, text.
- category wajib salah satu kunci berikut:
{$categories}
- confidence 0 sampai 1, seberapa yakin kamu membaca nominalnya.

Balas HANYA JSON dengan bentuk:
{"expenses":[{"spent_on":"YYYY-MM-DD","merchant":"...","description":"...","amount":0,"category":"...","source":"...","confidence":0.0}]}
PROMPT;
    }

    private function ask(string $prompt, array $images = []): array
    {
        $message = ['role' => 'user', 'content' => $prompt];
        if ($images) {
            $message['images'] = $images;
        }

        $response = Http::timeout(120)
            ->post(config('services.ollama.url').'/api/chat', [
                'model' => config('services.ollama.model'),
                'messages' => [$message],
                'format' => 'json',
                'stream' => false,
                'options' => ['temperature' => 0],
            ])
            ->throw()
            ->json('message.content');

        $data = json_decode($response, true) ?? [];

        return collect($data['expenses'] ?? [])
            ->map(fn ($row) => $this->clean($row))
            ->filter(fn ($row) => $row['amount'] > 0)
            ->values()
            ->all();
    }

    private function clean(array $row): array
    {
        $category = in_array($row['category'] ?? null, Categories::keys(), true)
            ? $row['category']
            : 'lainnya';

        $source = in_array($row['source'] ?? null, ['receipt', 'transfer', 'order', 'text', 'voice'], true)
            ? $row['source']
            : 'text';

        try {
            $date = Carbon::parse($row['spent_on'] ?? 'today')->toDateString();
        } catch (\Throwable) {
            $date = Carbon::now('Asia/Jakarta')->toDateString();
        }

        return [
            'spent_on' => $date,
            'merchant' => $row['merchant'] ?? null,
            'description' => $row['description'] ?? null,
            'amount' => (int) preg_replace('/\D/', '', (string) ($row['amount'] ?? 0)),
            'category' => $category,
            'source' => $source,
            'confidence' => (float) ($row['confidence'] ?? 0),
        ];
    }
}
