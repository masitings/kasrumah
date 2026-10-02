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
        $image = base64_encode($this->prepareImage($absolutePath));

        $prompt = <<<'PROMPT'
Gambar ini satu bukti pengeluaran dari Indonesia: struk belanja, screenshot bukti transfer m-banking, atau screenshot pesanan online.

Aturan khusus gambar:
- Satu gambar = SATU pengeluaran. expenses harus berisi tepat 1 item.
- amount = total belanja yang sebenarnya (baris TOTAL / TOTAL BELANJA / GRAND TOTAL / jumlah yang ditransfer).
- ABAIKAN baris pembayaran: TUNAI, CASH, KEMBALI, KEMBALIAN, DEBIT, QRIS, XENDIT, OVO, GOPAY, DANA, BAYAR, dan uang yang diserahkan. Itu cara bayar, bukan pengeluaran baru.
- merchant = nama brand toko singkat (contoh: "Indomaret", "Alfamart", "Apotek Gama"), BUKAN alamat atau nama cabang. Untuk bukti transfer, merchant = nama penerima.
- source: "receipt" untuk struk, "transfer" HANYA kalau gambarnya tampilan aplikasi m-banking/e-wallet, "order" untuk pesanan online.
- description = ringkas isi belanja, maksimal 6 kata (contoh: "Kinder Joy").
PROMPT;

        $rows = $this->ask($this->withCommonRules($prompt), [$image]);

        // Belt and braces: one image is one expense, keep the most confident row.
        return collect($rows)->sortByDesc('confidence')->take(1)->values()->all();
    }

    public function fromText(string $text): array
    {
        $prompt = <<<PROMPT
Ini catatan pengeluaran yang diketik atau didiktekan: "{$text}"

Aturan khusus teks:
- Setiap barang/keperluan dengan nominalnya sendiri = SATU item terpisah.
  Contoh: "beli sayur 45rb sama galon 20rb" = 2 item: sayur 45000 (dapur) dan galon 20000 (rumah).
- merchant = nama toko/layanan kalau disebut (contoh: "Grab", "PLN"), kalau tidak ada isi null.
- description = barang atau keperluannya (contoh: "sayur", "galon", "kondangan").
- source selalu "text".
PROMPT;

        return $this->ask($this->withCommonRules($prompt));
    }

    private function withCommonRules(string $specific): string
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $categories = Categories::forPrompt();

        return <<<PROMPT
{$specific}

Aturan umum:
- Hari ini tanggal {$today}. Kalau tanggal tidak terlihat atau tidak disebut, pakai hari ini.
- amount angka bulat rupiah tanpa titik. "rb"/"ribu"/"k" = x1000, "jt"/"juta" = x1000000.
- category wajib salah satu kunci berikut:
{$categories}
- confidence 0 sampai 1, seberapa yakin kamu dengan nominalnya.
- Jangan pernah mengembalikan expenses kosong kalau ada nominal yang terbaca.
PROMPT;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expenses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'spent_on' => ['type' => 'string'],
                            'merchant' => ['type' => ['string', 'null']],
                            'description' => ['type' => ['string', 'null']],
                            'amount' => ['type' => 'integer'],
                            'category' => ['type' => 'string', 'enum' => Categories::keys()],
                            'source' => ['type' => 'string', 'enum' => ['receipt', 'transfer', 'order', 'text']],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['spent_on', 'amount', 'category', 'source', 'confidence'],
                    ],
                ],
            ],
            'required' => ['expenses'],
        ];
    }

    private function ask(string $prompt, array $images = []): array
    {
        $message = ['role' => 'user', 'content' => $prompt];
        if ($images) {
            $message['images'] = $images;
        }

        $content = Http::timeout(120)
            ->post(config('services.ollama.url').'/api/chat', [
                'model' => config('services.ollama.model'),
                'messages' => [$message],
                'format' => $this->schema(), // Ollama structured outputs
                'stream' => false,
                'options' => ['temperature' => 0],
            ])
            ->throw()
            ->json('message.content');

        $data = json_decode($content ?? '', true) ?? [];

        return collect($data['expenses'] ?? [])
            ->map(fn ($row) => $this->clean($row) + ['raw' => $row])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * Downscale to max 1600px JPEG: faster inference, and avoids sending 12MP photos to the model.
     */
    private function prepareImage(string $path): string
    {
        $bytes = file_get_contents($path);
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            throw new \RuntimeException('Format gambar tidak didukung. Kirim JPG atau PNG.');
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, 1600 / max($w, $h));

        if ($scale < 1) {
            $dst = imagecreatetruecolor((int) ($w * $scale), (int) ($h * $scale));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
            $src = $dst;
        }

        ob_start();
        imagejpeg($src, null, 85);

        return ob_get_clean();
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
