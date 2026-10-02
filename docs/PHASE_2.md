# Task: Kas Rumah Phase 2 (fix extraction, then build the app)

Continue the existing `kas-rumah` project (Laravel + React starter kit, Gemma 3 4B via Ollama). Phase 1 is done; its test command `kas:test-extract` exists. Keep committing after every step with clear English messages. Do not add any cloud AI API. UI copy is casual Indonesian; the user is a wife managing household money on her iPhone.

Stop and report if a step fails. Do not change the stack.

## Step 1: Replace the extractor

Phase 1 found three problems: multi-item text returned `{}`, receipts produced an extra row for the payment line (XENDIT, TUNAI), and merchant came out as a branch address instead of the brand. Replace `app/Services/ExpenseExtractor.php` entirely with this version. It splits image and text prompts, uses Ollama structured outputs (JSON schema), forces one row per image, and downscales images before sending.

```php
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

```

PHP needs the GD extension (`php -m | grep -i gd`). If missing, stop and report.

Commit: `Split image and text prompts, use structured outputs, one row per image`.

## Step 2: Turn the test command into a regression check

Create `storage/app/test/expected.json` with the ground truth below, and update `kas:test-extract` to compare results against it and print PASS/FAIL per case (amount must match exactly; category must match; merchant compared case-insensitively with `str_contains`).

```json
{
  "IMG_1906.jpg": [{"amount": 25000, "category": "kesehatan", "merchant": "gama"}],
  "IMG_1908.jpg": [{"amount": 500000, "category": "lainnya", "merchant": "big apple"}],
  "IMG_1910.jpg": [{"amount": 17500, "category": "jajan", "merchant": "indomaret"}],
  "beli sayur 45rb sama galon 20rb": [
    {"amount": 45000, "category": "dapur"},
    {"amount": 20000, "category": "rumah"}
  ],
  "bayar listrik 350 ribu": [{"amount": 350000, "category": "tagihan"}],
  "grab ke kantor 28rb": [{"amount": 28000, "category": "transport"}],
  "kondangan 200rb": [{"amount": 200000, "category": "keluarga"}]
}

```

Run it and include the output in your final report. If any case fails, you may adjust the prompt wording only, rerun, and report what you changed. Do not loosen the comparison to make tests pass.

Commit: `Add extraction regression check`.

## Step 3: Pages

Mobile-first, big touch targets, one column. Use the starter kit's existing components and layout. Format money as `Rp17.500`. All routes behind auth.

1. **Catat** (`/`, home after login)
   - A large "Foto struk" button: `<input type="file" accept="image/jpeg,image/png" capture="environment">`. Limiting `accept` to JPEG/PNG makes iOS Safari convert HEIC to JPEG automatically, so no server-side HEIC handling is needed. Also allow picking from the photo library (second button without `capture`).
   - A text field "Atau ketik aja, misal: beli sayur 45rb sama galon 20rb" with a submit button. Mention in placeholder or helper text that the iPhone keyboard mic works for dictation (this is our voice input, no Whisper).
   - While Gemma works, show a friendly loading state ("Lagi dibaca...").
   - On success, redirect to Review.
2. **Review** (`/review`): list all `draft` expenses. Each row editable inline: date, merchant, description, amount, category (select with Indonesian labels from `Categories::ALL`). Show the receipt thumbnail when there is one, and highlight rows with confidence &lt; 0.7. Buttons per row: "Simpan" (status confirmed) and "Hapus". Plus "Simpan semua".
3. **Pengeluaran** (`/expenses`): confirmed expenses for the selected month, grouped by date, with the month total and a per-category total list at the top. Month switcher.
4. **Ringkasan** (`/summary`): calls `WeeklySummary::for()` and shows the paragraph plus the facts (this week per category vs 4-week average, budget usage bars). Add a "Bikin ulang" button. Cache the result per user per week until an expense changes.
5. **Budget** (`/budgets`): one number input per category for the monthly limit; empty means no budget.

Bottom tab bar across pages: Catat, Review (with draft count badge), Pengeluaran, Ringkasan.

Store uploaded images under `storage/app/private/receipts/{user_id}/` and serve thumbnails through an auth-checked route. Save `raw_extraction` (the `raw` key from the extractor) on each expense.

Commit after each page.

## Step 4: Tests

Use Pest with `Http::fake()` for the Ollama endpoint, so tests never hit the real model:

- text submit creates draft rows with the faked values
- image upload stores the file and creates exactly one draft
- confirm and delete only work on the owner's expenses
- the summary page renders with faked Ollama text
- the extractor maps an unknown category to `lainnya` and drops rows with amount 0

Commit: `Add feature tests`.

## Step 5: Run it on the phone

The app runs on the laptop; the iPhone reaches it over the same Wi-Fi.

- `npm run build` (so the phone does not need the Vite dev server)
- `php artisan serve --host=0.0.0.0 --port=8000`
- Print the laptop's LAN URL (e.g. [`http://192.168.x.x:8000`](http://192.168.x.x:8000)) in your report.
- Add a short "Run on your phone" section to [`README.md`](http://README.md) with these steps.

## Report back

- Step 2 regression output (and any prompt changes you made)
- Test results
- The LAN URL
- Screenshots of the 5 pages at iPhone width (390px) if you can take them
- Anything you skipped or could not finish

## Out of scope

Deployment, multi-user households, export, push notifications, Whisper/voice models.