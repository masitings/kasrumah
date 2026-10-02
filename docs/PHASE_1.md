# Task: Build "Kas Rumah" (Phase 1: foundation + Gemma test)

You are setting up a new Laravel project called **Kas Rumah**: a household expense tracker for the owner's wife. She snaps a receipt, a bank transfer screenshot, or types "beli sayur 45rb", and a **local Gemma model via Ollama** turns it into a categorized expense. Nothing leaves the laptop.

This is for the DEV Hacktoberfest Weekend Challenge ("Build for a Friend"). Rules that affect you:

- The repo must be **created fresh today** and every step committed to git, so the history proves it was built inside the challenge window.
- Open-source AI must be the core: Gemma 3 via Ollama, running locally. Do not add any cloud AI API.

Work through the steps in order. Stop and report if any step fails; do not invent workarounds that change the stack.

## Stack

- Laravel (latest) with the official **React starter kit** (Inertia + React), built-in auth
- SQLite
- Ollama with `gemma3:4b` (vision capable)
- Money is stored as integer rupiah, no decimals
- UI copy in casual Indonesian; code, comments and commit messages in English

## Step 1: Ollama

```bash
ollama pull gemma3:4b
ollama run gemma3:4b "halo"   # must reply; then exit

```

If Ollama is not installed, stop and ask the owner to install it from [ollama.com](http://ollama.com).

## Step 2: Create the project

```bash
laravel new kas-rumah   # choose: React starter kit, Laravel built-in auth, SQLite, run migrations, run npm install
cd kas-rumah
git init && git add . && git commit -m "Initial Laravel + React starter"

```

If the `laravel` installer is missing: `composer global require laravel/installer`.

## Step 3: Add the starter files

Create each file below with exactly this content.

### `database/migrations/2026_10_03_000000_create_expenses_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('spent_on');
            $table->string('merchant')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('amount'); // rupiah, no decimals
            $table->string('category'); // see App\Support\Categories
            $table->enum('source', ['receipt', 'transfer', 'order', 'text', 'voice']);
            $table->enum('status', ['draft', 'confirmed'])->default('draft');
            $table->string('image_path')->nullable();
            $table->json('raw_extraction')->nullable(); // keep model output for debugging + the write-up
            $table->timestamps();

            $table->index(['user_id', 'spent_on']);
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->unsignedBigInteger('monthly_limit');
            $table->timestamps();
            $table->unique(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('expenses');
    }
};

```

### `app/Models/Expense.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'user_id', 'spent_on', 'merchant', 'description', 'amount',
        'category', 'source', 'status', 'image_path', 'raw_extraction',
    ];

    protected $casts = [
        'spent_on' => 'date',
        'amount' => 'integer',
        'raw_extraction' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

```

### `app/Support/Categories.php`

```php
<?php

namespace App\Support;

class Categories
{
    public const ALL = [
        'dapur'      => 'Belanja dapur (sayur, lauk, bumbu, beras)',
        'jajan'      => 'Jajan & makan di luar',
        'online'     => 'Belanja online (Shopee, Tokopedia, dll)',
        'rumah'      => 'Kebutuhan rumah (sabun, gas, galon, perabot)',
        'tagihan'    => 'Tagihan (listrik, air, internet, pulsa)',
        'transport'  => 'Transport (bensin, ojol, parkir)',
        'kesehatan'  => 'Kesehatan & obat',
        'keluarga'   => 'Keluarga & sosial (kondangan, kiriman)',
        'lainnya'    => 'Lainnya',
    ];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function forPrompt(): string
    {
        return collect(self::ALL)
            ->map(fn ($label, $key) => "- {$key}: {$label}")
            ->implode("\n");
    }
}

```

### `app/Services/ExpenseExtractor.php`

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

```

### `app/Services/WeeklySummary.php`

```php
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

```

## Step 4: Wiring

1. `php artisan make:model Budget`, then in `app/Models/Budget.php`: 
   ```php
   protected $fillable = ['user_id', 'category', 'monthly_limit'];
   
   ```
2. In `app/Models/User.php`, add: 
   ```php
   public function budgets(){    return $this->hasMany(\App\Models\Budget::class);}public function expenses(){    return $this->hasMany(\App\Models\Expense::class);}
   
   ```
3. In `config/services.php`, add inside the returned array: 
   ```php
   'ollama' => [    'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),    'model' => env('OLLAMA_MODEL', 'gemma3:4b'),],
   
   ```
4. Append to `.env` and `.env.example`: 
   ```
   OLLAMA_URL=http://127.0.0.1:11434OLLAMA_MODEL=gemma3:4b
   
   ```
5. Run and commit: 
   ```bash
   php artisan migrategit add . && git commit -m "Add expenses, budgets, Gemma extractor and weekly summary"
   
   ```

## Step 5: Test the extractor

Create a test command so results are repeatable:

```bash
php artisan make:command TestExtractor

```

`app/Console/Commands/TestExtractor.php` should:

- have signature `kas:test-extract {--dir=storage/app/test}`
- run `ExpenseExtractor::fromImage()` on every `.jpg`, `.jpeg`, `.png` in that dir
- run `fromText()` on these samples: `beli sayur 45rb sama galon 20rb`, `bayar listrik 350 ribu`, `grab ke kantor 28rb`, `kondangan 200rb`
- print a table: file or text, merchant, amount, category, confidence, seconds taken
- write the full results to `storage/app/test/results.json`

The owner will put real receipt photos in `storage/app/test/`. If the folder is empty, run only the text samples and say so.

Commit: `git commit -am "Add extractor test command"`.

## Step 6: Report back

Reply with:

- whether every step succeeded
- the results table from Step 5
- average seconds per image and per text
- any rows where amount is 0, category is `lainnya`, or confidence &lt; 0.6

Troubleshooting:

- Connection refused on 11434: Ollama is not running (`ollama serve`).
- Timeouts: raise `timeout(120)` to `timeout(300)` in both services.
- Empty `expenses` array: print the raw `message.content` from Ollama and include it in the report. Do not silently change the prompt.

## Out of scope for this phase

Do not build controllers, pages, upload UI, voice input, or the weekly summary screen yet. Those come in Phase 2 after the extraction accuracy is known.