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
    public function fromImage(string $absolutePath, ?string $model = null): array
    {
        $image = base64_encode($this->prepareImage($absolutePath));

        $prompt = <<<'PROMPT'
Gambar ini satu bukti pengeluaran dari Indonesia: struk belanja kertas, faktur penjualan, screenshot bukti transfer m-banking/e-wallet, atau screenshot rincian pesanan online.

Aturan khusus gambar:
- Satu gambar = SATU pengeluaran. Array expenses harus berisi tepat 1 item.
- merchant: nama brand atau nama toko. Carilah satu kalimat / wordmark yang paling menonjol sebagai NAMA TOKO (biasanya paling besar, paling atas, atau di dekat logo).
  * Jika yang terbaca adalah alamat (Jl./Jalan/Perum/Kel./Kec./Kota/Alamat) BUKAN nama toko, abaikan dan cari lagi di bagian lain struk.
  * Selalu isi string merchant; jangan biarkan kosong. Untuk bukti transfer, merchant = nama penerima.
- category: tentukan berdasarkan jenis toko dan barang utamanya:
  * apotek / obat / vitamin / klinik / dokter -> "kesehatan"
  * minimarket / supermarket / warung: makanan, minuman, snack, kopi, jajan -> "jajan"; sayur, beras, lauk, bumbu dapur -> "dapur"; sabun, gas, galon, perlengkapan rumah -> "rumah"
  * toko elektronik / hp / gadget / barang mahal tanpa kategori pas -> "lainnya"
  * tagihan listrik / air / internet / pulsa -> "tagihan"
  * bensin / ojol / taksi / parkir -> "transport"
  * Kalau jenis tak bisa ditebak dari barang, pakai "lainnya".
- amount: total uang yang dibayar (angka bulat rupiah tanpa desimal dan tanpa titik).
  * Baca baris TOTAL, TOTAL BELANJA, GRAND TOTAL, TOTAL HARGA, atau JUMLAH DITRANSFER (total akhir setelah diskon/ongkir, bukan subtotal).
  * Angka di belakang koma seperti ',00' atau ',0' adalah desimal/sen: buang sen tersebut, JANGAN ubah menjadi nol tambahan (contoh: '25.000,00' = 25000, BUKAN 2500000 atau 230000).
  * Hati-hati membaca digit cetakan struk titik matrix: jangan sampai angka '5' terbaca '3' atau sebaliknya.
  * ABAIKAN baris pembayaran: TUNAI, CASH, KEMBALI, KEMBALIAN, DEBIT, QRIS, XENDIT, GOPAY, DANA, BAYAR, atau nominal uang yang diserahkan.
- source: "receipt" untuk struk/faktur belanja fisik, "transfer" untuk screenshot m-banking/e-wallet, "order" untuk rincian pesanan online.
- description: ringkas isi belanja atau nama barang utama, maksimal 6 kata (contoh: "belanja bulanan", "makan siang", "obat flu").
PROMPT;

        $rows = $this->ask($this->withCommonRules($prompt), [$image], $model);

        // Belt and braces: one image is one expense, keep the most confident row.
        return collect($rows)->sortByDesc('confidence')->take(1)->values()->all();
    }

    public function fromText(string $text, ?string $model = null): array
    {
        $prompt = <<<PROMPT
Ini catatan pengeluaran yang diketik atau didiktekan: "{$text}"

Aturan khusus teks:
- Setiap barang/keperluan dengan nominalnya sendiri = SATU item terpisah.
  Contoh: "beli rokok 15rb sama sabun 12rb" = 2 item: rokok 15000 (lainnya) dan sabun 12000 (rumah).
- merchant = nama toko/layanan kalau disebut (isi null kalau tidak disebut).
- description = barang atau keperluannya.
- source selalu "text".
PROMPT;

        return $this->ask($this->withCommonRules($prompt), [], $model);
    }

    private function withCommonRules(string $specific): string
    {
        $today = Carbon::now('Asia/Jakarta')->toDateString();
        $categories = Categories::forPrompt();

        return <<<PROMPT
{$specific}

Aturan umum:
- Hari ini tanggal {$today}. Kalau tanggal tidak terlihat atau tidak disebut, pakai hari ini.
- amount angka bulat rupiah penuh (contoh: 350000, bukan 350).
  * Satuan "rb", "ribu", atau "k" = KALIKAN 1000 (contoh: 350 ribu = 350000, 45rb = 45000, 28rb = 28000, 200rb = 200000).
  * Satuan "jt" atau "juta" = KALIKAN 1000000 (contoh: 1,5 juta = 1500000).
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

    private function ask(string $prompt, array $images = [], ?string $model = null): array
    {
        $message = ['role' => 'user', 'content' => $prompt];
        if ($images) {
            $message['images'] = $images;
        }

        $targetModel = $model ?: config('services.ollama.model');

        $content = Http::timeout(120)
            ->post(config('services.ollama.url').'/api/chat', [
                'model' => $targetModel,
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
