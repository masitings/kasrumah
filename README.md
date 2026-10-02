# Kas Rumah

Pencatat pengeluaran rumah tangga buat istri di rumah. Foto struk, screenshot transfer, atau ketik aja — **Gemma 3 (vision) dijalankan 100% lokal lewat Ollama**. Tidak ada satu pun data yang keluar dari laptop: tanpa API AI cloud, tanpa kirim gambar ke server orang lain.

```
Foto struk / screenshot / teks
        │
        ▼
ExpenseExtractor (app/Services)
        │   POST http://127.0.0.1:11434/api/chat
        │   format = JSON schema (structured outputs)
        ▼
Ollama + gemma3:4b  ──►  baris pengeluaran (amount, category, merchant, confidence)
        │
        ▼
Draf ──► Review (koreksi manual) ──► Konfirmasi ──► Pengeluaran / Ringkasan / Budget
```

- Uang disimpan sebagai **integer rupiah**, tanpa desimal.
- Copy UI berbahasa Indonesia santai; kode, komentar, dan commit message berbahasa Inggris.
- Semua route di balik auth; struk disajikan lewat route yang dicek kepemilikannya.

---

## Stack

| Bagian | Versi / catatan |
|---|---|
| PHP | 8.3+ (teruji di 8.4 / 8.5) |
| Laravel | 13, Inertia v3 + React + TypeScript (starter kit resmi, auth bawaan Fortify) |
| Build | Vite + Wayfinder |
| Database | SQLite (default `.env.example`) atau MySQL (dipakai saat dev) |
| AI lokal | Ollama + `gemma3:4b` (vision) di `http://127.0.0.1:11434` |
| Ekstensi PHP | **GD** wajib (downscale gambar sebelum dikirim ke model) |

---

## Persiapan

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Kalau pakai MySQL, samakan `DB_*` di `.env` lalu `php artisan migrate` lagi.

Ollama:

```bash
ollama pull gemma3:4b   # ±3.3 GB
ollama serve            # biasanya sudah jalan otomatis di macOS
```

Konfigurasi (opsional, ada di `config/services.php`):

```dotenv
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=gemma3:4b
```

## Development

```bash
composer run dev        # server + vite + queue + pail sekaligus
# atau terpisah:
php artisan serve
npm run dev
```

## Halaman

| Halaman | Route | Fungsi |
|---|---|---|
| **Catat** | `/` | Tombol kamera (`capture="environment"`), pilih galeri, atau ketik catatan |
| **Review** | `/review` | Koreksi draf inline (tanggal, merchant, keterangan, nominal, kategori); baris `confidence < 0.7` disorot; **Simpan / Hapus / Simpan Semua** |
| **Pengeluaran** | `/expenses` | Bulan terpilih (`?month=YYYY-MM`), dikelompokkan per tanggal + total per kategori |
| **Ringkasan** | `/summary` | Narasi mingguan Gemma + fakta (minggu ini vs rata-rata 4 minggu) + pemakaian budget |
| **Budget** | `/budgets` | Batas bulanan per kategori (kosong = tanpa batas) |

Bottom tab bar: Catat · Review (badge jumlah draf) · Pengeluaran · Ringkasan. Budget lewat ikon di header.

---

## Struktur kode penting

```
app/
├── Console/Commands/TestExtractor.php   # kas:test-extract (regresi tuning/holdout)
├── Http/Controllers/
│   ├── CatatController.php              # / + simpan teks & gambar
│   ├── ReviewController.php             # /review (edit, confirm, hapus)
│   ├── ExpenseController.php            # /expenses
│   ├── SummaryController.php            # /summary (+ cache)
│   ├── BudgetController.php             # /budgets
│   └── ReceiptController.php            # thumbnail struk (auth-checked)
├── Services/ExpenseExtractor.php        # prompt + panggilan Ollama (gambar & teks)
├── Services/WeeklySummary.php           # hitung angka di PHP, narasi oleh Gemma
└── Support/{Categories,SummaryCache}.php

resources/js/
├── layouts/kas-layout.tsx               # shell mobile + bottom tab bar
└── pages/kas/{catat,review,expenses,summary,budgets}.tsx
```

---

## Regresi ekstraksi

```bash
php artisan kas:test-extract                      # semua set, 1 run
php artisan kas:test-extract --set=tuning          # hanya set tuning
php artisan kas:test-extract --set=holdout --runs=3
php artisan kas:test-extract --model=gemma3:12b    # override model sekali jalan
```

- Fixture: `tests/fixtures/extraction/{tuning,holdout}/` masing-masing punya `expected.json` (amount harus persis; category harus sama; merchant dicek `str_contains` case-insensitive).
- `--runs=N`: sebuah kasus PASS hanya kalau **semua** N run lulus (output Gemma bisa bervariasi walau `temperature 0`).
- Gambar sensitif tidak di-commit: lihat `tests/fixtures/extraction/.gitignore`. Kasus yang file-nya tidak ada akan di-skip dengan catatan.

## Testing

```bash
php artisan test --compact        # atau vendor/bin/pest
```

Test memakai `Http::fake()` untuk endpoint Ollama, jadi tidak menyentuh model asli.

## Run on your phone

Server + Ollama jalan di laptop; iPhone mengakses lewat Wi-Fi yang sama.

1. Build aset (biar iPhone tidak butuh Vite dev server):
   ```bash
   npm run build
   ```
2. Nyalakan Ollama: `ollama serve`
3. Serve ke semua antarmuka (ganti port kalau 8000 sedang dipakai proses lain):
   ```bash
   php artisan serve --host=0.0.0.0 --port=8000
   ```
4. Cari IP LAN laptop: `ipconfig getifaddr en0`
5. Buka di Safari iPhone: `http://<IP-laptop>:8000`
6. Login, lalu **Tambahkan ke Layar Utama**.

Catatan:
- Kamera jalan di `http://` LAN. `accept="image/jpeg,image/png"` membuat iOS Safari otomatis mengonversi HEIC → JPEG, jadi tidak perlu penanganan HEIC di server.
- Cek model ter-load: `ollama ps`. Kalau port 11434 ditolak: `ollama serve`.

---

## Hasil & catatan kejujuran

Laporan lengkap ada di `docs/`.

| Fase | Isi | Ringkas |
|---|---|---|
| **Phase 1** | Ekstraksi pertama + command uji | Nominal akurat; merchant & teks multi-item bermasalah → dasar Phase 2 |
| **Phase 2** | Prompt dipisah, structured outputs, 5 halaman, tests | 7/7 regresi **tetapi** prompt memuat jawaban uji (bocor) |
| **Phase 2.1** | Hapus kebocoran prompt, ukur jujur (`gemma3:4b`, 3 run) | **Tuning gambar 1/3, teks 4/4, holdout 0/2**; avg 4.14 s/gambar, 3.50 s/teks |

Angka Phase 2.1 dengan prompt bersih: nominal & kategori sebagian besar benar; kegagalan terkonsentrasi pada **pemilihan merchant** untuk logo stylized dan pada screenshot (penerima transfer vs nama aplikasi, kedai vs platform). Detail di `docs/PHASE_2.1_RESULTS.md`.

## Keterbatasan

- **Merchant** pada logo miring/stylized (mis. Indomaret) sering tidak terbaca → kolom `merchant` bisa kosong dan perlu diisi manual di Review.
- **HEIC** tidak didukung server (GD/Ollama); andalkan konversi otomatis Safari iOS atau konversi manual (`sips -s format jpeg`).
- Struk buram/berpendar bisa membuat satu digit salah baca (mis. 25.000 vs 23.000); nominal tetap wajib dikonfirmasi di halaman Review.
- Ringkasan memakai cache per user per minggu; berubah otomatis saat ada pengeluaran baru.

## Out of scope

Deployment, multi-user household, export, push notification, model suara (Whisper).
